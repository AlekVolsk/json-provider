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
     * @param array<string,null|scalar> $record
     */
    public function matches(array $record): bool
    {
        if (!\array_key_exists($this->field, $record)) {
            return $this->not;
        }

        $result = $this->operator->matches($record[$this->field], $this->value);

        return $this->not ? !$result : $result;
    }
}
