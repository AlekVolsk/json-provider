<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Backup;

/**
 * Backup archive manifest — defensive header that lets the importer detect
 * whether an archive is a valid JsonProvider backup before reading it.
 *
 * Stored inside the archive as `manifest.json`.
 */
final class BackupManifest
{
    public const string FORMAT = 'jsonprovider-backup';
    public const int VERSION = 1;

    /**
     * @param array<int,string> $tables list of table names included in the
     *                                  archive
     */
    public function __construct(
        public readonly string $createdAt,
        public readonly array $tables,
        public readonly string $format = self::FORMAT,
        public readonly int $version = self::VERSION,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'format'    => $this->format,
            'version'   => $this->version,
            'createdAt' => $this->createdAt,
            'tables'    => $this->tables,
        ];
    }

    /**
     * @param array<mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $format = isset($raw['format']) && \is_string($raw['format'])
            ? $raw['format']
            : '';
        $version = isset($raw['version']) && \is_int($raw['version'])
            ? $raw['version']
            : 0;
        $createdAt = isset($raw['createdAt']) && \is_string($raw['createdAt'])
            ? $raw['createdAt']
            : '';
        $tablesRaw = isset($raw['tables']) && \is_array($raw['tables'])
            ? $raw['tables']
            : [];

        $tables = [];

        foreach ($tablesRaw as $t) {
            if (\is_string($t)) {
                $tables[] = $t;
            }
        }

        return new self(
            createdAt: $createdAt,
            tables: $tables,
            format: $format,
            version: $version,
        );
    }
}
