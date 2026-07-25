<?php

declare(strict_types=1);

namespace AV\JsonProvider\Relations;

use AV\JsonProvider\Schema\TableSchema;

/**
 * Fully validated multi-table write set produced by the FK plan phase:
 * for every mutated table the FINAL record list (normalized, floats
 * widened, cascade patches applied, deleted rows removed) and the order
 * to flip the files in. $affected counts the root rows the statement
 * deleted or updated.
 *
 * writeOrder lists children before their parents: a crash mid-commit then
 * leaves child rows already gone while the parent rows still exist, so
 * re-running the same statement converges; the opposite order would leave
 * orphaned children invisible to a re-run.
 *
 * @phpstan-type RecordSet array<int,array<string,null|scalar>>
 */
final class FkWritePlan
{
    /**
     * @param array<string,TableSchema> $schemas
     * @param array<string,RecordSet>   $tables
     * @param array<int,string>         $writeOrder
     */
    public function __construct(
        public readonly array $schemas,
        public readonly array $tables,
        public readonly array $writeOrder,
        public readonly string $rootTable,
        public readonly int $affected,
    ) {
    }
}
