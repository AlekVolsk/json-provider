<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

/**
 * Which user index addRelation(), dropIndex() and repair() may pick as the
 * backing index of a probing relation (cascade/restrict) instead of a
 * service index "_fk_<column>".
 *
 * SingleColumn (default): only an index on exactly the FK column.
 *
 * LeadingColumn: an index on exactly the FK column first, then any index
 * whose first field is the FK column — the table keeps one index less and
 * every write appends to one file less. FK probes compare the first key
 * part only, so such an index answers them exactly. Engines before 1.2
 * count a relation backed this way as uncovered: a restrict delete fails
 * with FkBackingIndexMissing and cascade/setNull read the child table
 * whole until their repair() provisions a service index.
 */
enum FkBackingPolicyEnum
{
    case SingleColumn;
    case LeadingColumn;

    /**
     * The user index of $indexes this policy lets back a relation on
     * $column, or null when none qualifies and a service index is needed.
     *
     * @param array<int,IndexSchema> $indexes
     */
    public function userBacking(
        array $indexes,
        string $column,
    ): IndexSchema | null {
        $leading = null;

        foreach ($indexes as $index) {
            if (
                $index->isService
                || $index->isPrimary
                || !$index->ledBy($column)
            ) {
                continue;
            }

            if (\count($index->fields) === 1) {
                return $index;
            }

            $leading ??= $index;
        }

        return $this === self::LeadingColumn ? $leading : null;
    }
}
