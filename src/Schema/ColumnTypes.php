<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

/**
 * Allowed column types for JsonProvider schemas.
 *
 * A column type is a plain string stored as the value in a table's `columns`
 * map (`'fieldName' => ColumnTypes::INT`). Records only ever hold scalars and
 * `null`; nested arrays are not supported. Each base type has a nullable
 * variant (`<type>|null`) that additionally permits `null`.
 *
 * Used purely as a named place for constants so the magic literals `'string'`,
 * `'int'`, `'float'`, `'bool'` (and their `|null` variants) are not scattered
 * across the codebase — the same role `PrimaryKey` plays for `'id'`/`'int'`.
 *
 * Temporal types (date/time/datetime and their millisecond `*z` variants) are
 * validated and normalized by the Validation layer. Instant-bearing values
 * (time, timez, datetime, datetimez) are stored in UTC and presented in the
 * current PHP timezone (date_default_timezone_get()); a bare `date` has no
 * instant and is stored verbatim. year/month/day are plain integer parts (year
 * may be negative for BC), validated by range but never timezone-shifted.
 */
final class ColumnTypes
{
    public const string STRING = 'string';
    public const string INT = 'int';
    public const string FLOAT = 'float';
    public const string BOOL = 'bool';

    public const string DATE = 'date';
    public const string TIME = 'time';
    public const string TIMEZ = 'timez';
    public const string DATETIME = 'datetime';
    public const string DATETIMEZ = 'datetimez';
    public const string YEAR = 'year';
    public const string MONTH = 'month';
    public const string DAY = 'day';

    public const string STRING_NULLABLE = 'string|null';
    public const string INT_NULLABLE = 'int|null';
    public const string FLOAT_NULLABLE = 'float|null';
    public const string BOOL_NULLABLE = 'bool|null';

    public const string DATE_NULLABLE = 'date|null';
    public const string TIME_NULLABLE = 'time|null';
    public const string TIMEZ_NULLABLE = 'timez|null';
    public const string DATETIME_NULLABLE = 'datetime|null';
    public const string DATETIMEZ_NULLABLE = 'datetimez|null';
    public const string YEAR_NULLABLE = 'year|null';
    public const string MONTH_NULLABLE = 'month|null';
    public const string DAY_NULLABLE = 'day|null';

    /**
     * The closed set of valid column type strings: 12 base types plus their
     * 12 "|null" variants. TableSchema validates every declared column
     * against this list.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::STRING,
            self::INT,
            self::FLOAT,
            self::BOOL,
            self::DATE,
            self::TIME,
            self::TIMEZ,
            self::DATETIME,
            self::DATETIMEZ,
            self::YEAR,
            self::MONTH,
            self::DAY,
            self::STRING_NULLABLE,
            self::INT_NULLABLE,
            self::FLOAT_NULLABLE,
            self::BOOL_NULLABLE,
            self::DATE_NULLABLE,
            self::TIME_NULLABLE,
            self::TIMEZ_NULLABLE,
            self::DATETIME_NULLABLE,
            self::DATETIMEZ_NULLABLE,
            self::YEAR_NULLABLE,
            self::MONTH_NULLABLE,
            self::DAY_NULLABLE,
        ];
    }

    public static function isValid(string $type): bool
    {
        return \in_array($type, self::all(), true);
    }
}
