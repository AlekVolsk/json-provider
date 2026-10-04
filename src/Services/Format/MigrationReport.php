<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Format;

/**
 * What a storage migration did.
 */
final class MigrationReport
{
    /**
     * @param list<string> $steps           generation steps run, as "1->2"
     * @param list<string> $refreshedTables tables whose indexes were rebuilt
     *                                      and stamped (or healed by a full
     *                                      rewrite)
     * @param list<string> $skippedTables   tables left unstamped: their data
     *                                      file holds lines that are not
     *                                      records, so their indexes cannot
     *                                      be certified; they keep working
     *                                      as under 1.0
     */
    public function __construct(
        public readonly int $fromGeneration,
        public readonly int $toGeneration,
        public readonly array $steps,
        public readonly array $refreshedTables,
        public readonly array $skippedTables,
    ) {
    }
}
