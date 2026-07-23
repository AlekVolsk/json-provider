<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Backup;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Storage\NdjsonStorage;

/**
 * Restores DB state from a .tar.gz archive produced by Backup::export.
 *
 * Behaviour:
 *
 *  1. Validate the archive (format, manifest, schema must match the
 *     current DB schema).
 *  2. Take a safety snapshot of the current DB into a temporary archive.
 *  3. Replace each table's NDJSON file from the archive (records normalized
 *     against the current schema, then sorted by id ASC).
 *  4. Rebuild every index and re-derive meta (lineCount, lastInsertedId).
 *  5. Delete the safety snapshot.
 *
 * If anything in steps 3-4 fails, the restore is rolled back from the safety
 * snapshot. If the rollback itself fails, both errors are reported in
 * StorageException::restoreFailed.
 */
final class Restore
{
    public function __construct(
        private readonly Backup $backup,
        private readonly SchemaRegistry $schema,
        private readonly MetaRegistry $meta,
        private readonly NdjsonStorage $ndjson,
        private readonly IndexManager $indexManager,
    ) {}

    /**
     * Restores DB state from the archive.
     */
    public function restore(string $archivePath): void
    {
        $this->ensureArchiveReadable($archivePath);

        $manifest = $this->readManifest($archivePath);
        $this->ensureSchemaMatches($manifest);

        $snapshotPath = $this->makeSnapshot();

        try {
            $this->applyArchive($archivePath);
        } catch (\Throwable $primary) {
            try {
                $this->applyArchive($snapshotPath);
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
     *
     * This is the same operation used both for the actual restore and for
     * rolling back from the safety snapshot.
     */
    private function applyArchive(string $archivePath): void
    {
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

            $records = $this->parseAndNormalize($raw, $tableSchema);

            $byteSize = $this->ndjson->writeRaw(
                $tableName,
                $tableSchema->getFileName(),
                $this->ndjson->encodeRecords($tableName, $records),
            );
            $this->indexManager->rebuild($tableSchema, $records);

            if (!$this->meta->hasEntry($tableName)) {
                $this->meta->initTable($tableName);
            }

            $this->meta->setLastInsertedId($tableName, $this->maxId($records));
            $this->meta->commitRewrite($tableName, \count($records), $byteSize);
        }
    }

    /**
     * Parses raw NDJSON, normalizes each record against the schema and sorts
     * by id ASC.
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function parseAndNormalize(
        string $raw,
        \AV\JsonProvider\Schema\TableSchema $tableSchema,
    ): array {
        $records = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (!\is_array($decoded)) {
                continue;
            }

            $record = [];

            foreach (array_keys($tableSchema->columns) as $column) {
                $value = $decoded[$column] ?? null;

                if (\is_scalar($value) || $value === null) {
                    $record[$column] = $value;
                } else {
                    $record[$column] = null;
                }
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
