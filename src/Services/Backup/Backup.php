<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Backup;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Exception\JsonProviderIoException;
use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\TableLockManager;

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
 * will be rebuilt by Restore. Only the files DECLARED by the schema
 * (getFileName per table) enter the archive — never a directory listing —
 * so temp files (*.tmp) of a concurrent writer can never leak in.
 *
 * The whole export runs under the database EX lock: no writer is in
 * flight, so all tables come from one committed generation and a
 * cross-table FK can never dangle inside the archive. The lock is held
 * through the compression as well. Nested acquisition inside restore
 * (which already holds db EX plus table EX locks) re-enters.
 *
 * The manifest carries lastInsertedId counters (from meta, not derived
 * from data) and sha256 checksums of every member, computed from the very
 * bytes put into the archive.
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
        private readonly MetaRegistry $meta,
        private readonly TableLockManager $locks,
    ) {
    }

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
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::BackupArchiveExists,
                $archivePath,
            );
        }

        $tarPath = substr($archivePath, 0, -3);

        if (file_exists($tarPath)) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::BackupArchiveExists,
                $tarPath,
            );
        }

        return $this->locks->withDatabase(
            fn (): string => $this->doExport($archivePath, $tarPath),
        );
    }

    /**
     * The critical section of export, under the database EX lock: read
     * every member into memory, checksum those exact bytes, build the
     * manifest, then write and compress the archive.
     */
    private function doExport(string $archivePath, string $tarPath): string
    {
        $this->schema->reload();
        $tables = $this->schema->getTables();
        $tableNames = array_keys($tables);
        sort($tableNames);

        $schemaJson = json_encode(
            $this->json->read('information_schema.json'),
            JSON_PRETTY_PRINT,
        );

        if ($schemaJson === false) {
            throw new JsonProviderIoException(
                JsonProviderErrorEn::FileNotWritable,
                $archivePath,
            );
        }

        $tableContents = [];
        $counters = [];
        $checksums = [
            'information_schema.json' => hash('sha256', $schemaJson),
        ];

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
            $member = 'tables/' . $tableSchema->getFileName();
            $tableContents[$member] = $contents;
            $checksums[$member] = hash('sha256', $contents);

            try {
                $counters[$tableName] = $this->meta
                    ->getLastInsertedId($tableName);
            } catch (JsonProviderException) {
                continue;
            }
        }

        $manifest = new BackupManifest(
            createdAt: (new \DateTimeImmutable())->format(DATE_ATOM),
            tables: $tableNames,
            counters: $counters,
            checksums: $checksums,
        );

        $manifestJson = json_encode($manifest->toArray(), JSON_PRETTY_PRINT);

        if ($manifestJson === false) {
            throw new JsonProviderIoException(
                JsonProviderErrorEn::FileNotWritable,
                $archivePath,
            );
        }

        $tar = new \PharData($tarPath, 0, null, \Phar::TAR);
        $tar->addFromString('manifest.json', $manifestJson);
        $tar->addFromString('information_schema.json', $schemaJson);

        foreach ($tableContents as $member => $contents) {
            $tar->addFromString($member, $contents);
        }

        $tar->compress(\Phar::GZ);
        unset($tar);

        if (!file_exists($archivePath)) {
            throw new JsonProviderIoException(
                JsonProviderErrorEn::FileNotWritable,
                $archivePath,
            );
        }

        if (file_exists($tarPath) && !unlink($tarPath)) {
            throw new JsonProviderIoException(
                JsonProviderErrorEn::FileNotWritable,
                $tarPath,
            );
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
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::BackupDestinationInsideDb,
                $path,
            );
        }
    }
}
