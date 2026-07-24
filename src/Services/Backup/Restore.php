<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Backup;

use AV\JsonProvider\Exception\StorageException;
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
 * errors are reported in StorageException::restoreFailed and the snapshot
 * path is kept.
 */
final class Restore
{
    public function __construct(
        private readonly Backup $backup,
        private readonly SchemaRegistry $schema,
        private readonly MetaRegistry $meta,
        private readonly NdjsonStorage $ndjson,
        private readonly IndexManager $indexManager,
        private readonly ValueValidator $values,
        private readonly TableLockManager $locks,
        private readonly LoggerInterface | null $logger = null,
    ) {}

    /**
     * Restores DB state from the archive.
     */
    public function restore(
        string $archivePath,
        bool $adoptArchivedSchema = false,
        bool $pruneExtraTables = false,
    ): void {
        $this->ensureArchiveReadable($archivePath);

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
                throw StorageException::backupSchemaMismatch(
                    'current DB has tables absent from the archive: '
                        . implode(', ', $extraTables)
                        . '; pass pruneExtraTables=true to drop them '
                        . 'during the adopting restore',
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
                throw StorageException::restoreFailed(
                    'primary error: ' . $primary->getMessage()
                        . '; rollback also failed: ' . $rollback->getMessage()
                        . '; snapshot kept at: ' . $snapshotPath,
                );
            }

            @unlink($snapshotPath);

            throw StorageException::restoreFailed(
                'restore failed and was rolled back; original error: '
                    . $primary->getMessage(),
            );
        }

        @unlink($snapshotPath);
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
            throw StorageException::backupArchiveCorrupt(
                'archive not found: ' . $archivePath,
            );
        }

        if (!is_readable($archivePath)) {
            throw StorageException::backupArchiveCorrupt(
                'archive not readable: ' . $archivePath,
            );
        }
    }

    private function readManifest(string $archivePath): BackupManifest
    {
        try {
            $tar = new \PharData($archivePath);
        } catch (\Throwable $e) {
            throw StorageException::backupArchiveCorrupt(
                'cannot open archive: ' . $e->getMessage(),
            );
        }

        if (!isset($tar['manifest.json'])) {
            throw StorageException::backupArchiveCorrupt(
                'manifest.json is missing',
            );
        }

        $raw = file_get_contents('phar://' . $archivePath . '/manifest.json');

        if ($raw === false) {
            throw StorageException::backupArchiveCorrupt(
                'manifest.json is unreadable',
            );
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            throw StorageException::backupArchiveCorrupt(
                'manifest.json is not valid JSON',
            );
        }

        $manifest = BackupManifest::fromArray($decoded);

        if ($manifest->format !== BackupManifest::FORMAT) {
            throw StorageException::backupArchiveCorrupt(
                'unexpected manifest format: "' . $manifest->format . '"',
            );
        }

        if ($manifest->version !== BackupManifest::VERSION) {
            throw StorageException::backupArchiveCorrupt(
                'unsupported manifest version: ' . $manifest->version,
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
            $bytes = @file_get_contents(
                'phar://' . $archivePath . '/' . $member,
            );

            if ($bytes === false) {
                throw StorageException::backupArchiveCorrupt(
                    'archive member with a declared checksum is missing '
                        . 'or unreadable: ' . $member,
                );
            }

            if (!hash_equals($expected, hash('sha256', $bytes))) {
                throw StorageException::backupChecksumMismatch($member);
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
        $raw = @file_get_contents(
            'phar://' . $archivePath . '/information_schema.json',
        );

        if ($raw === false) {
            throw StorageException::backupArchiveCorrupt(
                'information_schema.json is missing or unreadable',
            );
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            throw StorageException::backupArchiveCorrupt(
                'information_schema.json is not valid JSON',
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
            throw StorageException::backupSchemaMismatch(
                'archive is missing tables required by the current schema: '
                    . implode(', ', $missing),
            );
        }

        $extra = array_diff($archiveTables, $currentTables);

        if ($extra !== []) {
            throw StorageException::backupSchemaMismatch(
                'archive contains tables not declared in the current schema: '
                    . implode(', ', $extra),
            );
        }
    }

    /**
     * Creates a temporary safety snapshot of the current DB state.
     */
    private function makeSnapshot(): string
    {
        $tmp = sys_get_temp_dir() . '/jp-snapshot-'
            . bin2hex(random_bytes(8)) . '.tar.gz';

        return $this->backup->export($tmp);
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
                throw StorageException::backupArchiveCorrupt(
                    'archive entry missing: tables/'
                        . $tableSchema->getFileName(),
                );
            }

            $raw = file_get_contents($entryPath);

            if ($raw === false) {
                throw StorageException::backupArchiveCorrupt(
                    'archive entry unreadable: tables/'
                        . $tableSchema->getFileName(),
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
                    throw StorageException::invalidRecord(
                        $tableSchema->name,
                        'column "' . $column . '" of type ' . $type
                            . ' is missing from an archived record and has '
                            . 'no safe default; resolve manually',
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
