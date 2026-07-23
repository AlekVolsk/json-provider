<?php

declare(strict_types=1);

namespace AV\JsonProvider\Query;

/**
 * A single filter condition: field, operator, value, NOT flag.
 * Immutable value object — conditions are chained via JsonTable.
 */
final class FilterCondition
{
    /**
     * @param string $field record field name (top-level only)
     * @param mixed  $value BETWEEN: [from,to]; IN: list of scalars;
     *                      otherwise scalar|null
     * @param bool   $not   true = invert (NOT =, NOT IN, ...)
     */
    public function __construct(
        public readonly string $field,
        public readonly FilterOperatorEnum $operator,
        public readonly mixed $value,
        public readonly bool $not = false,
    ) {}

    /**
     * Returns whether the given record satisfies this condition.
     *
     * A missing field reads as null — a ghost row lacking the column
     * behaves exactly like a row holding null, so `field = null` finds
     * both and `NOT field = x` cannot silently match every ghost row.
     * Fields absent from the SCHEMA never reach this point: the query
     * layer rejects them with QUERY_UNKNOWN_COLUMN before matching.
     *
     * @param array<string,null|scalar> $record
     */
    public function matches(
        array $record,
        ComparisonMode $mode = ComparisonMode::Binary,
    ): bool {
        $result = $this->operator->matches(
            $record[$this->field] ?? null,
            $this->value,
            $mode,
        );

        return $this->not ? !$result : $result;
    }
}
