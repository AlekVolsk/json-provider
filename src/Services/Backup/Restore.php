<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Backup;

use AV\JsonProvider\Exception\JsonProviderDataException;
use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\ColumnDefaults;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\TableLockManager;
use AV\JsonProvider\Validation\ValueValidator;
use Psr\Log\LoggerInterface;

/**
 * Restores DB state from a .tar.gz archive produced by Backup::export.
 *
 * Behaviour:
 *
 *  1. Validate the archive: format, manifest, sha256 checksums of every
 *     member the manifest knows (BACKUP_CHECKSUM_MISMATCH on any
 *     difference — nothing is applied). Legacy archives without checksums
 *     restore without verification.
 *  2. Match schemas. By default the archive's table set must equal the
 *     current schema. With $adoptArchivedSchema the archived
 *     information_schema.json is validated through the strict schema
 *     loaders and REPLACES the current schema; tables present locally but
 *     absent from the archive are dropped only under $pruneExtraTables,
 *     otherwise the restore refuses before mutating anything.
 *  3. Take a safety snapshot of the current DB into a temporary archive.
 *  4. Replace each table's NDJSON file from the archive (records
 *     normalized against the schema, then sorted by id ASC), rebuild
 *     every index, restore lastInsertedId from the manifest counters
 *     (max with the stored ids), stamp the index format, and sweep
 *     undeclared files from the table directory.
 *  5. Delete the safety snapshot.
 *
 * The whole mutating part (snapshot + apply) runs under the database EX
 * lock plus EX locks on every involved table (current and archived), so
 * restore serializes against every writer and reader-writer. The nested
 * snapshot export re-enters the held database lock.
 *
 * If anything in step 4 fails, the restore is rolled back from the safety
 * snapshot (schema, data, counters). If the rollback itself fails, both
 * errors are reported in RestoreRolledBack / RestoreRollbackFailed
 * and the snapshot path is kept.
 */
final class Restore
{
    private const string ARCHIVE_TABLES_DIR = 'tables/';
    private const string SNAPSHOT_DIR_PREFIX = 'jp-snapshot-';
    private const string SNAPSHOT_FILE = 'snapshot.tar.gz';

    /**
     * The snapshot of a restore in flight: set while the database may be
     * half-applied, cleared once the snapshot is removed or deliberately
     * kept. Still set at process end means a fatal error interrupted the
     * restore — see reportAbandonedSnapshot().
     */
    private string | null $openSnapshot = null;

    public function __construct(
        private readonly Backup $backup,
        private readonly SchemaRegistry $schema,
        private readonly MetaRegistry $meta,
        private readonly NdjsonStorage $ndjson,
        private readonly IndexManager $indexManager,
        private readonly ValueValidator $values,
        private readonly TableLockManager $locks,
        private readonly LoggerInterface | null $logger = null,
    ) {
        register_shutdown_function(
            fn () => $this->reportAbandonedSnapshot(),
        );
    }

    /**
     * Restores DB state from the archive. The archive is read through a
     * private one-off copy (see PharArchive), so replacing the file at the
     * same path between two restores in one process is always seen.
     */
    public function restore(
        string $archivePath,
        bool $adoptArchivedSchema = false,
        bool $pruneExtraTables = false,
    ): void {
        $this->ensureArchiveReadable($archivePath);
        $copy = PharArchive::privateCopy($archivePath);

        try {
            $this->restoreFromCopy(
                $copy,
                $adoptArchivedSchema,
                $pruneExtraTables,
            );
        } finally {
            PharArchive::discard($copy);
        }
    }

    private function restoreFromCopy(
        string $archivePath,
        bool $adoptArchivedSchema,
        bool $pruneExtraTables,
    ): void {
        $manifest = $this->readManifest($archivePath);
        $this->verifyChecksums($archivePath, $manifest);

        $archivedState = null;

        if ($adoptArchivedSchema) {
            $archivedState = $this->schema->parseSnapshot(
                $this->readArchivedSchemaRaw($archivePath),
            );
        } else {
            $this->ensureSchemaMatches($manifest);
        }

        $this->locks->withDatabase(
            function () use (
                $archivePath,
                $manifest,
                $archivedState,
                $pruneExtraTables,
            ): void {
                $this->schema->reload();

                $planTables = array_keys($this->schema->getTables());

                if ($archivedState !== null) {
                    $planTables = array_merge(
                        $planTables,
                        array_keys($archivedState[0]),
                    );
                }

                $plan = array_fill_keys(array_unique($planTables), 'ex');

                $this->locks->withLocks(
                    $plan,
                    null,
                    function () use (
                        $archivePath,
                        $manifest,
                        $archivedState,
                        $pruneExtraTables,
                    ): void {
                        $this->restoreLocked(
                            $archivePath,
                            $manifest,
                            $archivedState,
                            $pruneExtraTables,
                        );
                    },
                );
            },
        );
    }

    /**
     * The critical section: schema checks re-run under the held locks (a
     * DDL between the pre-lock validation and the lock acquisition must
     * not slip through), then snapshot, then apply with rollback.
     *
     * @param null|array{
     *     array<string,TableSchema>,
     *     array<int,RelationSchema>
     * } $archivedState
     */
    private function restoreLocked(
        string $archivePath,
        BackupManifest $manifest,
        array | null $archivedState,
        bool $pruneExtraTables,
    ): void {
        if ($archivedState === null) {
            $this->ensureSchemaMatches($manifest);
        }

        $extraTables = [];

        if ($archivedState !== null) {
            $extraTables = array_diff(
                array_keys($this->schema->getTables()),
                array_keys($archivedState[0]),
            );

            if ($extraTables !== [] && !$pruneExtraTables) {
                throw new JsonProviderServiceException(
                    JsonProviderErrorEn::RestoreLocalTablesAbsent,
                    implode(', ', $extraTables),
                );
            }
        }

        $snapshotPath = $this->makeSnapshot();

        try {
            if ($archivedState !== null) {
                $this->schema->replaceAll(
                    $archivedState[0],
                    $archivedState[1],
                );
                $this->schema->reload();

                foreach ($extraTables as $tableName) {
                    $this->dropTablePhysical($tableName);
                }
            }

            $this->applyArchive($archivePath);
        } catch (\Throwable $primary) {
            try {
                $this->rollbackFromSnapshot($snapshotPath);
            } catch (\Throwable $rollback) {
                $this->openSnapshot = null;

                throw new JsonProviderServiceException(
                    JsonProviderErrorEn::RestoreRollbackFailed,
                    $primary->getMessage(),
                    $rollback->getMessage(),
                    $snapshotPath,
                );
            }

            $this->removeSnapshot($snapshotPath);

            throw new JsonProviderServiceException(
                JsonProviderErrorEn::RestoreRolledBack,
                $primary->getMessage(),
            );
        }

        $this->removeSnapshot($snapshotPath);
    }

    /**
     * Rolls the database back to the safety snapshot: the snapshot's own
     * schema is re-adopted (undoing a half-applied schema adoption),
     * tables the failed restore may have created beyond it are removed,
     * then the snapshot data and counters are re-applied. Idempotent.
     */
    private function rollbackFromSnapshot(string $snapshotPath): void
    {
        [$tables, $relations] = $this->schema->parseSnapshot(
            $this->readArchivedSchemaRaw($snapshotPath),
        );

        $extraTables = array_diff(
            array_keys($this->schema->getTables()),
            array_keys($tables),
        );

        $this->schema->replaceAll($tables, $relations);
        $this->schema->reload();

        foreach ($extraTables as $tableName) {
            $this->dropTablePhysical($tableName);
        }

        $this->applyArchive($snapshotPath);
    }

    /**
     * Physically removes a pruned table: the meta entry, every file in the
     * table directory and the directory itself, and the now-pointless lock
     * file (held EX by this frame). The schema entry is already gone with
     * the adopted schema.
     */
    private function dropTablePhysical(string $tableName): void
    {
        if ($this->meta->hasEntry($tableName)) {
            $this->meta->dropEntry($tableName);
        }

        $this->ndjson->deleteTable($tableName);
        $this->locks->deleteTableLock($tableName);
    }

    private function ensureArchiveReadable(string $archivePath): void
    {
        if (!file_exists($archivePath)) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveNotFound,
                $archivePath,
            );
        }

        if (!is_readable($archivePath)) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveNotReadable,
                $archivePath,
            );
        }
    }

    private function readManifest(string $archivePath): BackupManifest
    {
        try {
            $tar = new \PharData($archivePath);
        } catch (\Throwable $e) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveOpenFailed,
                $e->getMessage(),
            );
        }

        if (!isset($tar['manifest.json'])) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveManifestMissing,
            );
        }

        $raw = file_get_contents('phar://' . $archivePath . '/manifest.json');

        if ($raw === false) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveManifestUnreadable,
            );
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveManifestNotJson,
            );
        }

        $manifest = BackupManifest::fromArray($decoded);

        if ($manifest->format !== BackupManifest::FORMAT) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveManifestFormat,
                $manifest->format,
            );
        }

        if ($manifest->version !== BackupManifest::VERSION) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveManifestVersion,
                (string)$manifest->version,
            );
        }

        return $manifest;
    }

    /**
     * Verifies the sha256 of every archive member the manifest carries a
     * checksum for, before anything is applied. A legacy archive (no
     * checksums) passes without verification.
     */
    private function verifyChecksums(
        string $archivePath,
        BackupManifest $manifest,
    ): void {
        foreach ($manifest->checksums as $member => $expected) {
            $memberPath = 'phar://' . $archivePath . '/' . $member;
            $bytes = file_exists($memberPath)
                ? file_get_contents($memberPath)
                : false;

            if ($bytes === false) {
                throw new JsonProviderServiceException(
                    JsonProviderErrorEn::ArchiveMemberUnreadable,
                    $member,
                );
            }

            if (!hash_equals($expected, hash('sha256', $bytes))) {
                throw new JsonProviderServiceException(
                    JsonProviderErrorEn::BackupChecksumMismatch,
                    $member,
                );
            }
        }
    }

    /**
     * Reads and decodes the archived information_schema.json.
     *
     * @return array<mixed>
     */
    private function readArchivedSchemaRaw(string $archivePath): array
    {
        $schemaPath = 'phar://' . $archivePath . '/information_schema.json';
        $raw = file_exists($schemaPath)
            ? file_get_contents($schemaPath)
            : false;

        if ($raw === false) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveSchemaUnreadable,
            );
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveSchemaNotJson,
            );
        }

        return $decoded;
    }

    private function ensureSchemaMatches(BackupManifest $manifest): void
    {
        $currentTables = array_keys($this->schema->getTables());
        sort($currentTables);

        $archiveTables = $manifest->tables;
        sort($archiveTables);

        $missing = array_diff($currentTables, $archiveTables);

        if ($missing !== []) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::RestoreArchiveMissesTables,
                implode(', ', $missing),
            );
        }

        $extra = array_diff($archiveTables, $currentTables);

        if ($extra !== []) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::RestoreArchiveExtraTables,
                implode(', ', $extra),
            );
        }
    }

    /**
     * Creates the safety snapshot of the current DB state in a private
     * (0700) directory under the system temp directory.
     */
    private function makeSnapshot(): string
    {
        $dir = PharArchive::privateDir(self::SNAPSHOT_DIR_PREFIX);

        try {
            $this->openSnapshot = $this->backup->export(
                $dir . '/' . self::SNAPSHOT_FILE,
                validate: false,
            );
        } catch (\Throwable $e) {
            if (is_dir($dir)) {
                rmdir($dir);
            }

            throw $e;
        }

        return $this->openSnapshot;
    }

    private function removeSnapshot(string $snapshotPath): void
    {
        PharArchive::discard($snapshotPath);

        if (is_dir(\dirname($snapshotPath))) {
            rmdir(\dirname($snapshotPath));
        }
        $this->openSnapshot = null;
    }

    /**
     * A fatal error (memory exhaustion, timeout) interrupted a restore: the
     * database may hold a mix of archived and previous tables, and the
     * snapshot is the only copy of the state before the restore. It is kept
     * and its path is reported — to the logger, or to the PHP error log
     * when none is attached, next to the fatal error itself.
     */
    private function reportAbandonedSnapshot(): void
    {
        if ($this->openSnapshot === null) {
            return;
        }

        $message = 'restore interrupted by a fatal error; the database may '
            . 'be partially restored; the state before the restore is kept '
            . 'in ' . $this->openSnapshot . ' — restore it from there, then '
            . 'delete its directory';

        if ($this->logger !== null) {
            $this->logger->critical($message);
        } else {
            error_log($message);
        }
    }

    /**
     * Reads each table file from the archive, writes it back as canonical
     * (records normalized + sorted by id), then rebuilds indexes and meta.
     * The lastInsertedId counter comes from the counters of THIS archive's
     * manifest (never a caller-supplied one — a rollback must restore the
     * snapshot's counters, not the primary archive's), maxed with the
     * stored ids; a legacy archive falls back to max(id) alone. After the
     * rebuild the table directory is swept of files the schema does not
     * declare (stray index files of dropped indexes, orphaned *.tmp), so
     * a restore converges to the exact declared layout.
     *
     * Missing table files and meta entries are provisioned first, so an
     * adopting restore materializes archive-only tables from scratch.
     */
    private function applyArchive(string $archivePath): void
    {
        $manifest = $this->readManifest($archivePath);
        $tables = $this->schema->getTables();

        foreach ($tables as $tableName => $tableSchema) {
            $entryPath = 'phar://' . $archivePath . '/tables/'
                . $tableSchema->getFileName();

            if (!file_exists($entryPath)) {
                throw new JsonProviderServiceException(
                    JsonProviderErrorEn::ArchiveEntryMissing,
                    self::ARCHIVE_TABLES_DIR . $tableSchema->getFileName(),
                );
            }

            $raw = file_get_contents($entryPath);

            if ($raw === false) {
                throw new JsonProviderServiceException(
                    JsonProviderErrorEn::ArchiveEntryUnreadable,
                    self::ARCHIVE_TABLES_DIR . $tableSchema->getFileName(),
                );
            }

            $records = $this->values->widenFloats(
                $tableSchema,
                $this->parseAndNormalize($raw, $tableSchema),
            );

            if (
                !$this->ndjson->exists(
                    $tableName,
                    $tableSchema->getFileName(),
                )
            ) {
                $this->ndjson->createFile(
                    $tableName,
                    $tableSchema->getFileName(),
                );
            }

            foreach ($tableSchema->indexes as $index) {
                if (
                    !$this->ndjson->exists($tableName, $index->getFileName())
                ) {
                    $this->ndjson->createFile(
                        $tableName,
                        $index->getFileName(),
                    );
                }
            }

            $byteSize = $this->ndjson->writeRaw(
                $tableName,
                $tableSchema->getFileName(),
                $this->ndjson->encodeRecords($tableName, $records),
            );
            $this->indexManager->rebuild($tableSchema, $records);

            if (!$this->meta->hasEntry($tableName)) {
                $this->meta->initTable($tableName);
            }

            $counter = $manifest->counters[$tableName] ?? 0;
            $this->meta->setLastInsertedId(
                $tableName,
                max($counter, $this->maxId($records)),
            );
            $this->meta->commitRewrite($tableName, \count($records), $byteSize);
            $this->meta->stampIndexFormat($tableName, 2);

            $this->sweepUndeclaredFiles($tableSchema);
        }
    }

    /**
     * Deletes every file in the table directory the schema does not
     * declare (the data file and the declared index files are the whole
     * legal layout). Under the held database EX lock no writer is in
     * flight, so a *.tmp here is an orphan of a crash, not live work.
     */
    private function sweepUndeclaredFiles(TableSchema $tableSchema): void
    {
        $declared = [$tableSchema->getFileName() => true];

        foreach ($tableSchema->indexes as $index) {
            $declared[$index->getFileName()] = true;
        }

        foreach ($this->ndjson->listFiles($tableSchema->name) as $fileName) {
            if (isset($declared[$fileName])) {
                continue;
            }

            $this->ndjson->deleteFile($tableSchema->name, $fileName);
        }
    }

    /**
     * Parses raw NDJSON, normalizes each record against the schema and sorts
     * by id ASC. A column PRESENT in a record keeps its value verbatim
     * (including a present null — a violation validate() must keep
     * seeing); a column ABSENT from it is back-filled with the type's
     * default via ColumnDefaults — the same value a migration would have
     * written — never a blind null into a non-nullable column. A missing
     * non-nullable column with no safe default (temporal) cannot be
     * invented and fails the restore loudly. Unparseable lines are
     * skipped with a warning to the logger.
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function parseAndNormalize(
        string $raw,
        TableSchema $tableSchema,
    ): array {
        $records = [];

        foreach (explode("\n", $raw) as $lineNumber => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (!\is_array($decoded)) {
                $this->logger?->warning(
                    'restore: table "' . $tableSchema->name
                        . '": unparseable line ' . $lineNumber
                        . ' skipped: ' . substr($line, 0, 80),
                );

                continue;
            }

            $record = [];

            foreach ($tableSchema->columns as $column => $type) {
                if (\array_key_exists($column, $decoded)) {
                    $value = $decoded[$column];
                    $record[$column] = \is_scalar($value) || $value === null
                        ? $value
                        : null;

                    continue;
                }

                if (!ColumnDefaults::hasSafeDefault($type)) {
                    throw new JsonProviderDataException(
                        JsonProviderErrorEn::RecordArchivedColumnNoDefault,
                        $tableSchema->name,
                        $column,
                        $type,
                    );
                }

                $record[$column] = ColumnDefaults::forType($type);
            }

            $records[] = $record;
        }

        usort(
            $records,
            static function (array $a, array $b): int {
                $idA = $a[PrimaryKey::FIELD] ?? 0;
                $idB = $b[PrimaryKey::FIELD] ?? 0;
                $intA = \is_int($idA) ? $idA : 0;
                $intB = \is_int($idB) ? $idB : 0;

                return $intA <=> $intB;
            },
        );

        return $records;
    }

    /**
     * @param array<int,array<string,null|scalar>> $records
     */
    private function maxId(array $records): int
    {
        $max = 0;

        foreach ($records as $record) {
            $id = $record[PrimaryKey::FIELD] ?? null;

            if (\is_int($id) && $id > $max) {
                $max = $id;
            }
        }

        return $max;
    }
}
