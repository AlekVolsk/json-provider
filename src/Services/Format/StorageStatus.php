<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Format;

/**
 * Read-only snapshot of a database's storage format, as seen by this
 * engine: what generation the database is in, what migrateStorage() would
 * do, and whether the database may be written at all.
 */
final class StorageStatus
{
    /**
     * @param list<string> $compat        compat features the manifest sets
     * @param list<string> $roCompat      roCompat features the manifest sets
     * @param list<string> $incompat      incompat features the manifest sets
     * @param list<string> $pendingSteps  generation steps a migration runs,
     *                                    as "1->2"
     * @param list<string> $pendingTables tables a migration would rebuild
     *                                    and stamp
     */
    public function __construct(
        public readonly int $generation,
        public readonly int $engineGeneration,
        public readonly array $compat,
        public readonly array $roCompat,
        public readonly array $incompat,
        public readonly bool $readOnly,
        public readonly array $pendingSteps,
        public readonly array $pendingTables,
    ) {
    }

    /**
     * True when the database is in this engine's generation and every
     * table is stamped and fresh: a migration would change nothing.
     */
    public function isCurrent(): bool
    {
        return $this->pendingSteps === [] && $this->pendingTables === [];
    }
}
