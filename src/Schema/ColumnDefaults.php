<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

/**
 * Default values for freshly added or back-filled columns, by declared type.
 *
 * Nullable types default to null; the four base scalar types to their zero
 * value ('' / 0 / 0.0 / false). Types with no meaningful zero (temporal,
 * year/month/day) have no safe default: hasSafeDefault() reports false and
 * callers must reject adding such a not-null column to a non-empty table.
 * forType() returns null for them only as a formal fallback — guarded call
 * sites never persist it as a not-null value.
 *
 * Shared by migrateColumns (filling added columns) and IntegrityRepairer
 * (back-filling columns missing from a stored record), so both paths agree
 * on what a "missing" value becomes.
 */
final class ColumnDefaults
{
    public static function forType(
        string $type,
    ): bool | float | int | string | null {
        if (str_ends_with($type, '|null')) {
            return null;
        }

        return match ($type) {
            ColumnTypes::STRING => '',
            ColumnTypes::INT    => 0,
            ColumnTypes::FLOAT  => 0.0,
            ColumnTypes::BOOL   => false,
            default             => null,
        };
    }

    public static function hasSafeDefault(string $type): bool
    {
        if (str_ends_with($type, '|null')) {
            return true;
        }

        return match ($type) {
            ColumnTypes::STRING,
            ColumnTypes::INT,
            ColumnTypes::FLOAT,
            ColumnTypes::BOOL => true,
            default           => false,
        };
    }
}
