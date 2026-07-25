<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Backup;

/**
 * Backup archive manifest — defensive header that lets the importer detect
 * whether an archive is a valid JsonProvider backup before reading it.
 *
 * Stored inside the archive as `manifest.json`.
 *
 * Validation fields (VERSION stays 1, the fields are optional):
 *
 *  - counters: tableName => lastInsertedId at export time, taken from the
 *    meta registry, NOT derived from data. Restore uses them so a deleted
 *    high-water id is never re-minted after a roundtrip.
 *  - checksums: archive member name ('information_schema.json' or
 *    'tables/<file>') => sha256 hex of its bytes. Restore verifies every
 *    member with a known checksum before applying anything. manifest.json
 *    itself is not covered — swapping the whole manifest together with the
 *    members it describes stays undetectable (accepted residual window;
 *    store archives with an external integrity layer when that matters).
 *
 * An archive missing both keys is a legacy (pre-checksum) backup: it
 * restores without verification and with counters derived as max(id).
 */
final class BackupManifest
{
    public const string FORMAT = 'jsonprovider-backup';
    public const int VERSION = 1;

    /**
     * @param array<int,string>    $tables    list of table names included
     *                                        in the archive
     * @param array<string,int>    $counters  tableName => lastInsertedId
     * @param array<string,string> $checksums member name => sha256 hex
     */
    public function __construct(
        public readonly string $createdAt,
        public readonly array $tables,
        public readonly string $format = self::FORMAT,
        public readonly int $version = self::VERSION,
        public readonly array $counters = [],
        public readonly array $checksums = [],
        public readonly bool $legacy = false,
    ) {
    }

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
            'counters'  => $this->counters,
            'checksums' => $this->checksums,
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

        $legacy = !\array_key_exists('counters', $raw)
            && !\array_key_exists('checksums', $raw);

        $countersRaw = isset($raw['counters']) && \is_array($raw['counters'])
            ? $raw['counters']
            : [];
        $counters = [];

        foreach ($countersRaw as $table => $value) {
            if (\is_string($table) && \is_int($value)) {
                $counters[$table] = $value;
            }
        }

        $checksumsRaw = isset($raw['checksums'])
            && \is_array($raw['checksums'])
            ? $raw['checksums']
            : [];
        $checksums = [];

        foreach ($checksumsRaw as $member => $hash) {
            if (\is_string($member) && \is_string($hash)) {
                $checksums[$member] = $hash;
            }
        }

        return new self(
            createdAt: $createdAt,
            tables: $tables,
            format: $format,
            version: $version,
            counters: $counters,
            checksums: $checksums,
            legacy: $legacy,
        );
    }
}
