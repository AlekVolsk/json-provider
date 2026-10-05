<?php

declare(strict_types=1);

namespace AV\JsonProvider\Engine;

use AV\JsonProvider\Query\ComparisonModeEnum;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Validation\ColumnTypeInfo;

/**
 * Chooses the index a query goes through: an index matching the
 * ordering, else the one covering most leading fields with equality
 * conditions and a range, else the first one led by the field of an
 * applicable condition.
 *
 * @internal
 */
final class IndexPlanner
{
    public function __construct(
        private readonly Context $context,
    ) {
    }

    /**
     * Picks an index for the query: first one matching the requested
     * ordering, then the first one whose leading fields the conditions
     * narrow the most when that is two fields or more (indexPrefix), then
     * one whose first field is filtered by an indexable condition.
     * Service (FK backing) indexes take part like user ones; one goes away
     * with its relation, and a select over the column then falls back to
     * a full scan.
     *
     * In Locale comparison mode the byte-ordered index disagrees with the
     * collator, so string columns are excluded from index-driven ordering
     * and ranges; string EQ/IN stay indexable (equality is byte-exact in
     * both modes).
     *
     * @param array<int,OrderBy>         $ordering
     * @param array<int,FilterCondition> $conditions
     */
    public function resolveIndex(
        TableSchema $tableSchema,
        array $ordering,
        array $conditions,
        bool $pushPagination = false,
    ): IndexSchema | null {
        if ($tableSchema->indexes === []) {
            return null;
        }

        /*
         * An ordering index pays off only when limit/offset can ride along
         * with it: the engine then walks the index in order and stops at the
         * limit, reading just the rows it returns.
         *
         * Without that cut it reads the whole table BY LINE NUMBER — a linear
         * pass over the data file on top of parsing the index file — and then
         * still filters. That is strictly more work than a plain full scan
         * followed by a sort, and it also steals the choice from a far more
         * selective condition index: `WHERE bucket = 7 ORDER BY title` used to
         * pull all rows in title order to keep a hundred of them.
         *
         * So the ordering index is considered only when pagination can be
         * pushed into it; otherwise the condition index below wins, and the
         * ordering is applied to whatever it returns.
         */
        if (
            $pushPagination
            && $ordering !== []
            && $this->orderingIndexable($tableSchema, $ordering)
        ) {
            foreach ($tableSchema->indexes as $index) {
                if ($index->matchesOrdering($ordering)) {
                    return $index;
                }
            }
        }

        $widest = null;
        $widestFields = 1;

        foreach ($tableSchema->indexes as $index) {
            $prefix = $this->indexPrefix($tableSchema, $index, $conditions);
            $fields = $prefix['fields'];

            if ($fields > $widestFields) {
                $widest = $index;
                $widestFields = $fields;
            }
        }

        if ($widest !== null) {
            return $widest;
        }

        foreach ($conditions as $condition) {
            if (!$this->conditionIndexServable($tableSchema, $condition)) {
                continue;
            }

            foreach ($tableSchema->indexes as $index) {
                $firstField = $index->fields[0] ?? null;

                if (
                    $firstField !== null
                    && $firstField->field === $condition->field
                ) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * How far the conditions narrow a lookup in the index: the values that
     * `=` conditions fix for its leading fields, in index order, and a
     * range condition on the field right after them. $fields counts the
     * fields used. Only conditions an index may serve take part
     * (conditionIndexServable), and only values a key can encode.
     *
     * @param array<int,FilterCondition> $conditions
     *
     * @return array{
     *     values: array<int,null|bool|float|int|string>,
     *     range: null|FilterCondition,
     *     fields: int,
     * }
     */
    public function indexPrefix(
        TableSchema $tableSchema,
        IndexSchema $index,
        array $conditions,
    ): array {
        $values = [];
        $range = null;

        foreach ($index->fields as $field) {
            $equal = null;
            $bounded = null;

            foreach ($conditions as $condition) {
                if (
                    $condition->not
                    || $condition->field !== $field->field
                    || !$this->conditionIndexServable($tableSchema, $condition)
                ) {
                    continue;
                }

                $value = $condition->value;

                if ($condition->operator === FilterOperatorEnum::EQ) {
                    $encodable = $value === null
                        || (\is_scalar($value)
                            && (!\is_float($value) || is_finite($value)));

                    if ($encodable && $equal === null) {
                        $equal = [$value];
                    }
                } elseif (
                    \in_array($condition->operator, [
                        FilterOperatorEnum::GT,
                        FilterOperatorEnum::GTE,
                        FilterOperatorEnum::LT,
                        FilterOperatorEnum::LTE,
                        FilterOperatorEnum::BETWEEN,
                    ], true)
                ) {
                    $bounded ??= $condition;
                }
            }

            if ($equal !== null) {
                $values[] = $equal[0];

                continue;
            }

            $range = $bounded;

            break;
        }

        return [
            'values' => $values,
            'range'  => $range,
            'fields' => \count($values) + ($range !== null ? 1 : 0),
        ];
    }

    /**
     * Whether an index may serve this condition. Equality (EQ/IN) always
     * qualifies — the strict `===` post-filter corrects any key-space
     * nuance. Range operators qualify only when the column's value order
     * provably matches the index key order: known typed columns in Binary
     * mode; string columns are excluded in Locale mode (collator vs byte
     * order) and passthrough columns always (their cross-type comparator
     * order differs from the key tag order).
     */
    public function conditionIndexServable(
        TableSchema $tableSchema,
        FilterCondition $condition,
    ): bool {
        if (
            $condition->not
            || $condition->operator === FilterOperatorEnum::LIKE
        ) {
            return false;
        }

        if (
            $condition->operator === FilterOperatorEnum::EQ
            || $condition->operator === FilterOperatorEnum::IN
        ) {
            return true;
        }

        if (!$this->rangeIndexableColumn($tableSchema, $condition->field)) {
            return false;
        }

        return $this->context->comparisonMode !== ComparisonModeEnum::Locale
            || !$this->isStringColumn($tableSchema, $condition->field);
    }

    /**
     * Ordering may ride an index only when every ordered column's value
     * order matches the key order — same rule as range conditions.
     *
     * @param array<int,OrderBy> $ordering
     */
    public function orderingIndexable(
        TableSchema $tableSchema,
        array $ordering,
    ): bool {
        foreach ($ordering as $order) {
            if (!$this->rangeIndexableColumn($tableSchema, $order->field)) {
                return false;
            }

            if (
                $this->context->comparisonMode === ComparisonModeEnum::Locale
                && $this->isStringColumn($tableSchema, $order->field)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * A column whose engine value order provably matches the v2 key
     * order: the known scalar types and the temporal types (stored as
     * canonical strings whose strcmp order is chronological). The
     * unknown-type (passthrough) branch is defense in depth only — the
     * schema boundary rejects unknown column types, so it is unreachable
     * through any supported path — but stays: mixed scalars have a
     * comparator order that differs from the key tag order, and ranges
     * or ordering over them must never trust an index.
     */
    private function rangeIndexableColumn(
        TableSchema $tableSchema,
        string $field,
    ): bool {
        $type = $tableSchema->columns[$field] ?? null;

        if ($type === null) {
            return false;
        }

        $info = ColumnTypeInfo::parse($type);

        if ($info->temporalKind() !== null) {
            return true;
        }

        return match ($info->base) {
            ColumnTypes::STRING,
            ColumnTypes::INT,
            ColumnTypes::FLOAT,
            ColumnTypes::BOOL,
            ColumnTypes::YEAR,
            ColumnTypes::MONTH,
            ColumnTypes::DAY => true,
            default          => false,
        };
    }

    private function isStringColumn(
        TableSchema $tableSchema,
        string $field,
    ): bool {
        $type = $tableSchema->columns[$field] ?? null;

        if ($type === null) {
            return false;
        }

        return ColumnTypeInfo::parse($type)->base === ColumnTypes::STRING;
    }
}
