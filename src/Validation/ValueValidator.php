<?php

declare(strict_types=1);

namespace AV\JsonProvider\Validation;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\TableSchema;

/**
 * Strict, schema-driven validation and encoding of record values.
 *
 * Types were previously descriptive only — a column declared `int` accepted
 * anything. This layer enforces the declared type on every write and converts
 * temporal values across the storage boundary:
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
 * accepted only for `<type>|null` columns. Unknown/custom type strings are
 * passed through unchecked for backward compatibility.
 *
 * Tables without a single temporal column pay nothing on read: decodeRecord and
 * encodeConditions short-circuit via a memoized per-schema check.
 */
final class ValueValidator
{
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
                throw StorageException::requiredColumnMissing(
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
     * Encodes temporal values inside filter conditions so they compare against
     * the stored UTC form. Non-temporal fields and LIKE are left as-is. Returns
     * the conditions unchanged when the table has no temporal columns.
     *
     * @param array<int,FilterCondition> $conditions
     *
     * @return array<int,FilterCondition>
     */
    public function encodeConditions(
        TableSchema $schema,
        array $conditions,
    ): array {
        if (!$this->hasTemporalColumns($schema)) {
            return $conditions;
        }

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
        $kind = $type === null
            ? null
            : ColumnTypeInfo::parse($type)->temporalKind();

        if (
            $kind === null
            || $condition->operator === FilterOperatorEnum::LIKE
        ) {
            return $condition;
        }

        $value = match ($condition->operator) {
            FilterOperatorEnum::IN => $this->encodeConditionList(
                $schema->name,
                $condition->field,
                $kind,
                $condition->value,
            ),
            FilterOperatorEnum::BETWEEN => $this->encodeConditionRange(
                $schema->name,
                $condition->field,
                $kind,
                $condition->value,
            ),
            default => $this->encodeConditionScalar(
                $schema->name,
                $condition->field,
                $kind,
                $condition->value,
            ),
        };

        return new FilterCondition(
            $condition->field,
            $condition->operator,
            $value,
            $condition->not,
        );
    }

    private function encodeConditionScalar(
        string $table,
        string $column,
        TemporalKind $kind,
        mixed $value,
    ): mixed {
        if (!\is_string($value)) {
            return $value;
        }

        return $this->encodeTemporal($table, $column, $kind, $value);
    }

    /**
     * @return array<int,mixed>
     */
    private function encodeConditionList(
        string $table,
        string $column,
        TemporalKind $kind,
        mixed $value,
    ): array {
        if (!\is_array($value)) {
            return [];
        }

        return array_map(
            fn (mixed $item): mixed => $this->encodeConditionScalar(
                $table,
                $column,
                $kind,
                $item,
            ),
            array_values($value),
        );
    }

    /**
     * @return array<int,mixed>
     */
    private function encodeConditionRange(
        string $table,
        string $column,
        TemporalKind $kind,
        mixed $value,
    ): array {
        if (!\is_array($value) || \count($value) !== 2) {
            return \is_array($value) ? array_values($value) : [];
        }

        [$from, $to] = array_values($value);

        return [
            $this->encodeConditionScalar($table, $column, $kind, $from),
            $this->encodeConditionScalar($table, $column, $kind, $to),
        ];
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

            throw StorageException::nullNotAllowed($table, $column);
        }

        $kind = $info->temporalKind();

        if ($kind !== null) {
            if (!\is_string($value)) {
                throw StorageException::typeMismatch(
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
        TemporalKind $kind,
        string $value,
    ): string {
        try {
            return $this->codec->encode($kind, $value);
        } catch (TemporalParseException $e) {
            if ($e->zeroDate) {
                throw StorageException::zeroDate($table, $column, $value);
            }

            throw StorageException::invalidTemporalValue(
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
            throw StorageException::typeMismatch(
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
            throw StorageException::numericPartOutOfRange(
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
            throw StorageException::typeMismatch(
                $table,
                $column,
                ColumnTypes::STRING,
                get_debug_type($value),
            );
        }

        if (preg_match('//u', $value) !== 1) {
            throw StorageException::invalidUtf8($table, $column);
        }

        return $value;
    }

    private function requireInt(
        string $table,
        string $column,
        mixed $value,
    ): int {
        if (!\is_int($value)) {
            throw StorageException::typeMismatch(
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
            throw StorageException::typeMismatch(
                $table,
                $column,
                ColumnTypes::FLOAT,
                get_debug_type($value),
            );
        }

        if (!is_finite($value)) {
            throw StorageException::nonFiniteFloat($table, $column);
        }

        return $value;
    }

    private function requireBool(
        string $table,
        string $column,
        mixed $value,
    ): bool {
        if (!\is_bool($value)) {
            throw StorageException::typeMismatch(
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
            throw StorageException::typeMismatch(
                $table,
                $column,
                'scalar',
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
