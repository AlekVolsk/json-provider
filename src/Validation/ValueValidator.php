<?php

declare(strict_types=1);

namespace AV\JsonProvider\Validation;

use AV\JsonProvider\Exception\JsonProviderDataException;
use AV\JsonProvider\Exception\JsonProviderQueryException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\TableSchema;

/**
 * Strict, schema-driven validation and encoding of record values.
 *
 * The declared column type is enforced on every write, and temporal values
 * are converted across the storage boundary:
 *
 *  - write side (encodeForWrite / encodeConditions): the caller's local values
 *    are validated against the column type and, for instant-bearing temporal
 *    columns, converted to UTC. Condition values are encoded too, so index
 *    lookups and the strict `=` comparison line up with the stored UTC form.
 *  - read side (decodeRecord): stored UTC values are converted back to the
 *    current PHP timezone right at the presentation boundary — never on the
 *    internal raw-read paths that feed indexes, uniqueness and foreign keys.
 *
 * Strictness (chosen contract): a value must match the declared PHP type
 * exactly; the single widening allowed is int into a float column. `null` is
 * accepted only for `<type>|null` columns. The passthrough branch for an
 * unknown type string is defense in depth only — the schema boundary
 * rejects every type outside the closed ColumnTypes list, so no supported
 * path can reach it.
 *
 * Tables without a single temporal column pay nothing on decodeRecord — it
 * short-circuits via a memoized per-schema check. encodeConditions always
 * runs the full validate-and-encode pass.
 */
final class ValueValidator
{
    private const string CONTEXT_WHERE = 'where';
    private const string TYPE_NULL = 'null';
    private const string TYPE_SCALAR = 'scalar';

    /** @var \WeakMap<TableSchema,bool> */
    private \WeakMap $temporalMemo;

    /** @var \WeakMap<TableSchema,array<int,string>> */
    private \WeakMap $floatColumnsMemo;

    public function __construct(
        private readonly TemporalCodec $codec = new TemporalCodec(),
    ) {
        $this->temporalMemo = new \WeakMap();
        $this->floatColumnsMemo = new \WeakMap();
    }

    /**
     * Validates and encodes an inbound record/patch against the schema.
     *
     * Returns a new map holding only known, non-`id` columns that were present,
     * with temporal values converted to their stored (UTC) form. `id` is never
     * validated here (the provider assigns it). Unknown keys are dropped.
     *
     * With $requireNonNullable (insert), a missing non-nullable column is an
     * error; without it (update patch), absent columns are simply left out.
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,null|scalar>
     */
    public function encodeForWrite(
        TableSchema $schema,
        array $record,
        bool $requireNonNullable,
    ): array {
        $out = [];

        foreach ($schema->columns as $column => $type) {
            if ($column === PrimaryKey::FIELD) {
                continue;
            }

            $info = ColumnTypeInfo::parse($type);

            if (\array_key_exists($column, $record)) {
                $out[$column] = $this->encodeValue(
                    $schema->name,
                    $column,
                    $info,
                    $record[$column],
                );

                continue;
            }

            if ($requireNonNullable && !$info->nullable) {
                throw new JsonProviderDataException(
                    JsonProviderErrorEn::RequiredColumnMissing,
                    $schema->name,
                    $column,
                );
            }
        }

        return $out;
    }

    /**
     * Widens int values stored in float columns to PHP floats.
     *
     * JSON has one number type: a float written without a fractional part by
     * an older writer (or an external editor) decodes back as int. Every read
     * path runs records through this method so float columns always surface
     * as PHP floats — strict `=`/IN comparisons, unique keys, FK probes and
     * DTO hydration then compare float to float. Idempotent; tables without
     * float columns pay a single memoized check. Fresh writes keep the
     * fraction on disk via JSON_PRESERVE_ZERO_FRACTION, so this is the
     * safety net for legacy rows, not the primary format.
     *
     * @param array<int,array<string,null|scalar>> $records
     *
     * @return array<int,array<string,null|scalar>>
     */
    public function widenFloats(TableSchema $schema, array $records): array
    {
        $columns = $this->floatColumns($schema);

        if ($columns === []) {
            return $records;
        }

        foreach ($records as $i => $record) {
            foreach ($columns as $column) {
                $value = $record[$column] ?? null;

                if (\is_int($value)) {
                    $records[$i][$column] = (float)$value;
                }
            }
        }

        return $records;
    }

    /**
     * Converts stored (UTC) temporal values of a single record back to the
     * current PHP timezone. Non-temporal values pass through untouched. Returns
     * the record unchanged when the table has no temporal columns.
     *
     * @param array<string,null|scalar> $record
     *
     * @return array<string,null|scalar>
     */
    public function decodeRecord(TableSchema $schema, array $record): array
    {
        if (!$this->hasTemporalColumns($schema)) {
            return $record;
        }

        foreach ($schema->columns as $column => $type) {
            if (!\array_key_exists($column, $record)) {
                continue;
            }

            $kind = ColumnTypeInfo::parse($type)->temporalKind();
            $value = $record[$column];

            if ($kind !== null && \is_string($value)) {
                $record[$column] = $this->codec->decode($kind, $value);
            }
        }

        return $record;
    }

    /**
     * Full validate-and-encode pass over filter conditions — the write
     * contract mirrored onto the read side. Per condition, in order:
     *
     *  1. the column must exist in the schema (QUERY_UNKNOWN_COLUMN);
     *  2. structure: BETWEEN takes exactly [min, max], IN takes an array
     *     (empty = matches nothing) — CONDITION_MALFORMED otherwise;
     *     checked for every column type including passthrough;
     *  3. value typing, mirroring encodeForWrite: EQ takes a scalar or
     *     null (null is allowed regardless of nullability and simply
     *     matches null cells); GT/GTE/LT/LTE and BETWEEN bounds take a
     *     non-null scalar of the column's exact type; IN elements follow
     *     the EQ rule; LIKE takes a string and only works on
     *     string/temporal/passthrough columns. The single coercion is int
     *     into a float column; numeric STRINGS are rejected, as are
     *     NAN/INF (same guard as the write path). Unknown (passthrough)
     *     column types skip the value checks;
     *  4. temporal values are encoded to their stored UTC form so index
     *     lookups and strict `=` compare against what is on disk.
     *
     * The first violation throws; the query never executes.
     *
     * @param array<int,FilterCondition> $conditions
     *
     * @return array<int,FilterCondition>
     */
    public function encodeConditions(
        TableSchema $schema,
        array $conditions,
    ): array {
        $out = [];

        foreach ($conditions as $condition) {
            $out[] = $this->encodeCondition($schema, $condition);
        }

        return $out;
    }

    private function encodeCondition(
        TableSchema $schema,
        FilterCondition $condition,
    ): FilterCondition {
        $type = $schema->columns[$condition->field] ?? null;

        if ($type === null) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::QueryUnknownColumn,
                $schema->name,
                $condition->field,
                self::CONTEXT_WHERE,
            );
        }

        $info = ColumnTypeInfo::parse($type);
        $value = match ($condition->operator) {
            FilterOperatorEnum::BETWEEN => $this->conditionBetween(
                $schema->name,
                $condition->field,
                $info,
                $condition->value,
            ),
            FilterOperatorEnum::IN => $this->conditionIn(
                $schema->name,
                $condition->field,
                $info,
                $condition->value,
            ),
            FilterOperatorEnum::LIKE => $this->conditionLike(
                $schema->name,
                $condition->field,
                $info,
                $condition->value,
            ),
            FilterOperatorEnum::EQ => $this->conditionScalar(
                $schema->name,
                $condition->field,
                '=',
                $info,
                $condition->value,
                true,
            ),
            default => $this->conditionScalar(
                $schema->name,
                $condition->field,
                $condition->operator->value,
                $info,
                $condition->value,
                false,
            ),
        };

        return new FilterCondition(
            $condition->field,
            $condition->operator,
            $value,
            $condition->not,
        );
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private function conditionBetween(
        string $table,
        string $column,
        ColumnTypeInfo $info,
        mixed $value,
    ): array {
        if (!\is_array($value) || \count($value) !== 2) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::ConditionBetweenShape,
                $table,
                $column,
                FilterOperatorEnum::BETWEEN->value,
            );
        }

        $bounds = array_values($value);

        foreach ($bounds as $bound) {
            if (!\is_scalar($bound)) {
                throw new JsonProviderQueryException(
                    JsonProviderErrorEn::ConditionBetweenShape,
                    $table,
                    $column,
                    FilterOperatorEnum::BETWEEN->value,
                );
            }
        }

        return [
            $this->conditionScalar(
                $table,
                $column,
                FilterOperatorEnum::BETWEEN->value,
                $info,
                $bounds[0],
                false,
            ),
            $this->conditionScalar(
                $table,
                $column,
                FilterOperatorEnum::BETWEEN->value,
                $info,
                $bounds[1],
                false,
            ),
        ];
    }

    /**
     * @return array<int,mixed>
     */
    private function conditionIn(
        string $table,
        string $column,
        ColumnTypeInfo $info,
        mixed $value,
    ): array {
        if (!\is_array($value)) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::ConditionInShape,
                $table,
                $column,
                FilterOperatorEnum::IN->value,
            );
        }

        $out = [];

        foreach (array_values($value) as $item) {
            if (!\is_scalar($item) && $item !== null) {
                throw new JsonProviderQueryException(
                    JsonProviderErrorEn::ConditionInShape,
                    $table,
                    $column,
                    FilterOperatorEnum::IN->value,
                );
            }

            $out[] = $this->conditionScalar(
                $table,
                $column,
                FilterOperatorEnum::IN->value,
                $info,
                $item,
                true,
            );
        }

        return $out;
    }

    private function conditionLike(
        string $table,
        string $column,
        ColumnTypeInfo $info,
        mixed $value,
    ): string {
        if (!\is_string($value)) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::ConditionExpectsStringPattern,
                $table,
                $column,
                FilterOperatorEnum::LIKE->value,
                get_debug_type($value),
            );
        }

        if (
            $this->conditionKnownBase($info)
            && $info->temporalKind() === null
            && $info->base !== ColumnTypes::STRING
        ) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::LikeOnNonStringColumn,
                $table,
                $column,
                $info->base,
            );
        }

        $kind = $info->temporalKind();

        if (
            $kind === TemporalKindEnum::DateTime
            || $kind === TemporalKindEnum::DateTimeZ
        ) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::LikeOnInstantUnsupported,
                $table,
                $column,
            );
        }

        return $value;
    }

    /**
     * Validates and encodes one condition value against the column type:
     * the EQ rule ($allowNull) or the range rule (non-null). Mirrors
     * encodeValue on the write side, including the int-to-float widening
     * and the non-finite guard.
     */
    private function conditionScalar(
        string $table,
        string $column,
        string $operator,
        ColumnTypeInfo $info,
        mixed $value,
        bool $allowNull,
    ): bool | float | int | string | null {
        if ($value === null) {
            if ($allowNull) {
                return null;
            }

            throw new JsonProviderQueryException(
                JsonProviderErrorEn::ConditionExpectsNonNullScalar,
                $table,
                $column,
                $operator,
                self::TYPE_NULL,
            );
        }

        if (!\is_scalar($value)) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::ConditionExpectsScalar,
                $table,
                $column,
                $operator,
                get_debug_type($value),
            );
        }

        if (!$this->conditionKnownBase($info)) {
            return $value;
        }

        $kind = $info->temporalKind();

        if ($kind !== null) {
            if (!\is_string($value)) {
                throw new JsonProviderQueryException(
                    JsonProviderErrorEn::ConditionExpectsTemporalString,
                    $table,
                    $column,
                    $operator,
                    $kind->value,
                    get_debug_type($value),
                );
            }

            return $this->encodeTemporal($table, $column, $kind, $value);
        }

        /*
         * Validate-and-encode per base: each branch checks the value's type
         * itself (narrowing it for the encode that follows), so the correct
         * PHP type is proven by the guard, not asserted.
         */
        if ($info->base === ColumnTypes::FLOAT) {
            if (!\is_int($value) && !\is_float($value)) {
                throw new JsonProviderQueryException(
                    JsonProviderErrorEn::ConditionExpectsFloatOrInt,
                    $table,
                    $column,
                    $operator,
                    get_debug_type($value),
                );
            }

            if (\is_float($value) && !is_finite($value)) {
                throw new JsonProviderDataException(
                    JsonProviderErrorEn::NonFiniteFloat,
                    $table,
                    $column,
                );
            }

            return (float)$value;
        }

        if ($info->base === ColumnTypes::STRING) {
            if (!\is_string($value)) {
                throw new JsonProviderQueryException(
                    JsonProviderErrorEn::ConditionExpectsType,
                    $table,
                    $column,
                    $operator,
                    ColumnTypes::STRING,
                    get_debug_type($value),
                );
            }

            return $this->requireString($table, $column, $value);
        }

        if (
            $info->base === ColumnTypes::YEAR
            || $info->base === ColumnTypes::MONTH
            || $info->base === ColumnTypes::DAY
        ) {
            if (!\is_int($value)) {
                throw new JsonProviderQueryException(
                    JsonProviderErrorEn::ConditionExpectsType,
                    $table,
                    $column,
                    $operator,
                    ColumnTypes::INT,
                    get_debug_type($value),
                );
            }

            return $this->encodeNumericPart(
                $table,
                $column,
                $info->base,
                $value,
            );
        }

        if ($info->base === ColumnTypes::INT && !\is_int($value)) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::ConditionExpectsType,
                $table,
                $column,
                $operator,
                ColumnTypes::INT,
                get_debug_type($value),
            );
        }

        if ($info->base === ColumnTypes::BOOL && !\is_bool($value)) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::ConditionExpectsType,
                $table,
                $column,
                $operator,
                ColumnTypes::BOOL,
                get_debug_type($value),
            );
        }

        return $value;
    }

    /**
     * Whether the column's base type is known to the validator — unknown
     * (passthrough) type strings skip value checks for backward
     * compatibility, exactly as on the write side.
     */
    private function conditionKnownBase(ColumnTypeInfo $info): bool
    {
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

    private function encodeValue(
        string $table,
        string $column,
        ColumnTypeInfo $info,
        mixed $value,
    ): bool | float | int | string | null {
        if ($value === null) {
            if ($info->nullable) {
                return null;
            }

            throw new JsonProviderDataException(
                JsonProviderErrorEn::NullNotAllowed,
                $table,
                $column,
            );
        }

        $kind = $info->temporalKind();

        if ($kind !== null) {
            if (!\is_string($value)) {
                throw new JsonProviderDataException(
                    JsonProviderErrorEn::TypeMismatch,
                    $table,
                    $column,
                    $info->base,
                    get_debug_type($value),
                );
            }

            return $this->encodeTemporal($table, $column, $kind, $value);
        }

        return match ($info->base) {
            ColumnTypes::YEAR,
            ColumnTypes::MONTH,
            ColumnTypes::DAY => $this->encodeNumericPart(
                $table,
                $column,
                $info->base,
                $value,
            ),
            ColumnTypes::STRING => $this->requireString(
                $table,
                $column,
                $value,
            ),
            ColumnTypes::INT   => $this->requireInt($table, $column, $value),
            ColumnTypes::FLOAT => $this->requireFloat($table, $column, $value),
            ColumnTypes::BOOL  => $this->requireBool($table, $column, $value),
            default            => $this->passthroughScalar(
                $table,
                $column,
                $value,
            ),
        };
    }

    private function encodeTemporal(
        string $table,
        string $column,
        TemporalKindEnum $kind,
        string $value,
    ): string {
        try {
            return $this->codec->encode($kind, $value);
        } catch (TemporalParseException $e) {
            if ($e->zeroDate) {
                throw new JsonProviderDataException(
                    JsonProviderErrorEn::ZeroDate,
                    $table,
                    $column,
                    $value,
                );
            }

            if ($e->fractionUnsupported) {
                throw new JsonProviderDataException(
                    JsonProviderErrorEn::TemporalFractionUnsupported,
                    $table,
                    $column,
                    $kind->value,
                    $value,
                );
            }

            throw new JsonProviderDataException(
                JsonProviderErrorEn::InvalidTemporalValue,
                $table,
                $column,
                $kind->value,
                $value,
            );
        }
    }

    private function encodeNumericPart(
        string $table,
        string $column,
        string $base,
        mixed $value,
    ): int {
        if (!\is_int($value)) {
            throw new JsonProviderDataException(
                JsonProviderErrorEn::TypeMismatch,
                $table,
                $column,
                $base,
                get_debug_type($value),
            );
        }

        $ok = match ($base) {
            ColumnTypes::MONTH => $value >= 1 && $value <= 12,
            ColumnTypes::DAY   => $value >= 1 && $value <= 31,
            default            => true,
        };

        if (!$ok) {
            throw new JsonProviderDataException(
                JsonProviderErrorEn::NumericPartOutOfRange,
                $table,
                $column,
                $base,
                (string)$value,
            );
        }

        return $value;
    }

    /**
     * The UTF-8 check relies on PCRE only (an empty /u pattern fails to
     * match invalid UTF-8 subjects), so no ext-mbstring is required.
     */
    private function requireString(
        string $table,
        string $column,
        mixed $value,
    ): string {
        if (!\is_string($value)) {
            throw new JsonProviderDataException(
                JsonProviderErrorEn::TypeMismatch,
                $table,
                $column,
                ColumnTypes::STRING,
                get_debug_type($value),
            );
        }

        if (preg_match('//u', $value) !== 1) {
            throw new JsonProviderDataException(
                JsonProviderErrorEn::InvalidUtf8,
                $table,
                $column,
            );
        }

        return $value;
    }

    private function requireInt(
        string $table,
        string $column,
        mixed $value,
    ): int {
        if (!\is_int($value)) {
            throw new JsonProviderDataException(
                JsonProviderErrorEn::TypeMismatch,
                $table,
                $column,
                ColumnTypes::INT,
                get_debug_type($value),
            );
        }

        return $value;
    }

    /**
     * NAN and INF are rejected: json_encode cannot represent them, so they
     * would abort the write later with a generic INVALID_RECORD instead of
     * pointing at the offending column.
     */
    private function requireFloat(
        string $table,
        string $column,
        mixed $value,
    ): float {
        if (\is_int($value)) {
            return (float)$value;
        }

        if (!\is_float($value)) {
            throw new JsonProviderDataException(
                JsonProviderErrorEn::TypeMismatch,
                $table,
                $column,
                ColumnTypes::FLOAT,
                get_debug_type($value),
            );
        }

        if (!is_finite($value)) {
            throw new JsonProviderDataException(
                JsonProviderErrorEn::NonFiniteFloat,
                $table,
                $column,
            );
        }

        return $value;
    }

    private function requireBool(
        string $table,
        string $column,
        mixed $value,
    ): bool {
        if (!\is_bool($value)) {
            throw new JsonProviderDataException(
                JsonProviderErrorEn::TypeMismatch,
                $table,
                $column,
                ColumnTypes::BOOL,
                get_debug_type($value),
            );
        }

        return $value;
    }

    private function passthroughScalar(
        string $table,
        string $column,
        mixed $value,
    ): bool | float | int | string {
        if (!\is_scalar($value)) {
            throw new JsonProviderDataException(
                JsonProviderErrorEn::TypeMismatch,
                $table,
                $column,
                self::TYPE_SCALAR,
                get_debug_type($value),
            );
        }

        return $value;
    }

    /**
     * @return array<int,string>
     */
    private function floatColumns(TableSchema $schema): array
    {
        if (isset($this->floatColumnsMemo[$schema])) {
            return $this->floatColumnsMemo[$schema];
        }

        $columns = [];

        foreach ($schema->columns as $column => $type) {
            if (ColumnTypeInfo::parse($type)->base === ColumnTypes::FLOAT) {
                $columns[] = $column;
            }
        }

        $this->floatColumnsMemo[$schema] = $columns;

        return $columns;
    }

    private function hasTemporalColumns(TableSchema $schema): bool
    {
        if (isset($this->temporalMemo[$schema])) {
            return $this->temporalMemo[$schema];
        }

        $has = false;

        foreach ($schema->columns as $type) {
            if (ColumnTypeInfo::parse($type)->temporalKind() !== null) {
                $has = true;

                break;
            }
        }

        $this->temporalMemo[$schema] = $has;

        return $has;
    }
}
