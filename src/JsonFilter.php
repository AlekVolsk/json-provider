<?php

declare(strict_types=1);

namespace AV\JsonProvider;

use AV\JsonProvider\Exception\JsonProviderQueryException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;

/**
 * Immutable bag of filter conditions for a query.
 *
 * Built up via fluent `where`/`condition` methods; every call returns a new
 * instance with the appended condition, leaving the original untouched. This
 * lets the same filter be applied to multiple table queries:
 *
 *   $f = (new JsonFilter())
 *       ->where('categoryId', '=', $categoryId)
 *       ->where('active',     '=', true);
 *
 *   $total = $db->table('products')->setFilter($f)->count();
 *   $page  = $db->table('products')
 *       ->setFilter($f)
 *       ->orderBy('price', 'asc')
 *       ->limit(10)
 *       ->selectAllByArray();
 *
 * All conditions are AND-combined (OR-grouping is intentionally not supported
 * — it would change the semantics; tracked as a TODO at the domain layer).
 */
final class JsonFilter
{
    /**
     * @param array<int,FilterCondition> $conditions
     */
    public function __construct(
        private readonly array $conditions = [],
    ) {
    }

    /**
     * Adds a condition built from raw arguments. Returns a new filter.
     * An unknown operator string is rejected with the localized
     * INVALID_FILTER_OPERATOR (the table-less twin of the builder's
     * INVALID_OPERATOR — a filter is built before any table is chosen).
     */
    public function where(
        string $field,
        string $operator,
        mixed $value,
        bool $not = false,
    ): self {
        $op = FilterOperatorEnum::tryFrom($operator);

        if ($op === null) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::InvalidFilterOperator,
                $operator,
            );
        }

        return new self([
            ...$this->conditions,
            new FilterCondition($field, $op, $value, $not),
        ]);
    }

    /**
     * Adds a pre-built FilterCondition. Returns a new filter.
     */
    public function condition(FilterCondition $condition): self
    {
        return new self([...$this->conditions, $condition]);
    }

    /**
     * Returns whether the filter has no conditions.
     */
    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }

    /**
     * Returns the conditions as a flat list (for application to a query
     * builder).
     *
     * @return array<int,FilterCondition>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }
}
