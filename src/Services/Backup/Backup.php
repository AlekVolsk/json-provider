<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Backup;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;

/**
 * Exports the current DB state to a single .tar.gz archive.
 *
 * Archive layout:
 *
 *   manifest.json
 *   information_schema.json
 *   tables/
 *     <table-name>.ndjson
 *     ...
 *
 * Indexes and meta.json are NOT included — both are derived from data and
 * will be rebuilt by Restore.
 *
 * Built on top of the bundled PHP extensions ext-phar and ext-zlib; no
 * external commands or composer packages are required.
 */
final class Backup
{
    private const string DEFAULT_NAME_PREFIX = 'backup-';
    private const string ARCHIVE_EXT = '.tar.gz';

    public function __construct(
        private readonly string $dbPath,
        private readonly SchemaRegistry $schema,
        private readonly JsonStorage $json,
        private readonly NdjsonStorage $ndjson,
    ) {}

    /**
     * Writes a backup archive at $destination. Returns the absolute path of
     * the created archive.
     *
     * If $destination is an existing directory or has no .tar.gz/.tar
     * extension, a default file name "backup-YYYY-MM-DD_HHMMSS.tar.gz" is
     * appended.
     *
     * Throws if:
     *  - the resolved archive path already exists,
     *  - the resolved archive path is inside the DB directory.
     */
    public function export(string $destination): string
    {
        $archivePath = $this->resolveDestination($destination);

        $this->guardOutsideDbPath($archivePath);

        if (file_exists($archivePath)) {
            throw StorageException::backupArchiveExists($archivePath);
        }

        $tarPath = substr($archivePath, 0, -3);

        if (file_exists($tarPath)) {
            throw StorageException::backupArchiveExists($tarPath);
        }

        $tar = new \PharData($tarPath, 0, null, \Phar::TAR);

        $tables = $this->schema->getTables();
        $tableNames = array_keys($tables);
        sort($tableNames);

        $manifest = new BackupManifest(
            createdAt: (new \DateTimeImmutable())->format(DATE_ATOM),
            tables: $tableNames,
        );

        $manifestJson = json_encode($manifest->toArray(), JSON_PRETTY_PRINT);

        if ($manifestJson === false) {
            throw StorageException::fileNotWritable($archivePath);
        }

        $tar->addFromString('manifest.json', $manifestJson);

        $schemaJson = json_encode(
            $this->json->read('information_schema.json'),
            JSON_PRETTY_PRINT,
        );

        if ($schemaJson === false) {
            throw StorageException::fileNotWritable($archivePath);
        }

        $tar->addFromString('information_schema.json', $schemaJson);

        foreach ($tableNames as $tableName) {
            $tableSchema = $tables[$tableName];
            $contents = $this->ndjson->exists(
                $tableName,
                $tableSchema->getFileName(),
            )
                ? $this->ndjson->readRaw(
                    $tableName,
                    $tableSchema->getFileName(),
                )
                : '';
            $tar->addFromString(
                'tables/' . $tableSchema->getFileName(),
                $contents,
            );
        }

        $tar->compress(\Phar::GZ);
        unset($tar);

        if (!file_exists($archivePath)) {
            throw StorageException::fileNotWritable($archivePath);
        }

        if (file_exists($tarPath) && !unlink($tarPath)) {
            throw StorageException::fileNotWritable($tarPath);
        }

        return $archivePath;
    }

    /**
     * Resolves the user-supplied destination to a concrete .tar.gz file path.
     */
    private function resolveDestination(string $destination): string
    {
        if (is_dir($destination)) {
            $stamp = (new \DateTimeImmutable())->format('Y-m-d_His');

            return rtrim($destination, '/') . '/'
                . self::DEFAULT_NAME_PREFIX . $stamp . self::ARCHIVE_EXT;
        }

        if (str_ends_with($destination, self::ARCHIVE_EXT)) {
            return $destination;
        }

        if (str_ends_with($destination, '.tar')) {
            return $destination . '.gz';
        }

        return $destination . self::ARCHIVE_EXT;
    }

    /**
     * Refuses any path located inside the DB directory: a backup file there
     * would itself become an orphan from the integrity validator's point of
     * view.
     */
    private function guardOutsideDbPath(string $path): void
    {
        $dbReal = realpath($this->dbPath);

        if ($dbReal === false) {
            return;
        }

        $parent = \dirname($path);
        $parentReal = realpath($parent);

        if ($parentReal === false) {
            return;
        }

        if (
            $parentReal === $dbReal
            || str_starts_with($parentReal . '/', $dbReal . '/')
        ) {
            throw StorageException::backupDestinationInsideDb($path);
        }
    }
}
