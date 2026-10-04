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
    /** @var array<string,\Closure(array<mixed>): bool> */
    private array $predicates = [];

    public function __construct(
        public readonly string $field,
        public readonly FilterOperatorEnum $operator,
        public readonly mixed $value,
        public readonly bool $not = false,
    ) {
    }

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
        ComparisonModeEnum $mode = ComparisonModeEnum::Binary,
    ): bool {
        return ($this->predicates[$mode->name] ??= $this->predicate($mode))(
            $record,
        );
    }

    /**
     * The condition as one test of a record, built once and run per row of
     * a scan: equality and LIKE are specialised (the LIKE pattern is parsed
     * here, see LikePattern), every other operator goes through
     * FilterOperatorEnum::matches().
     *
     * @return \Closure(array<mixed>): bool
     */
    public function predicate(
        ComparisonModeEnum $mode = ComparisonModeEnum::Binary,
    ): \Closure {
        $field = $this->field;
        $value = $this->value;
        $operator = $this->operator;

        if ($operator === FilterOperatorEnum::EQ) {
            return $this->not
                ? static fn (array $r): bool => ($r[$field] ?? null) !== $value
                : static fn (array $r): bool => ($r[$field] ?? null) === $value;
        }

        if ($operator === FilterOperatorEnum::LIKE) {
            $test = \is_string($value)
                ? LikePattern::compile($value)->predicate($field)
                : static fn (array $r): bool => false;
        } else {
            $test = static function (array $r) use (
                $field,
                $operator,
                $value,
                $mode,
            ): bool {
                $recordValue = $r[$field] ?? null;

                return (\is_scalar($recordValue) || $recordValue === null)
                    && $operator->matches($recordValue, $value, $mode);
            };
        }

        return $this->not
            ? static fn (array $r): bool => !$test($r)
            : $test;
    }
}
