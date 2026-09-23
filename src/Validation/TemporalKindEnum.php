<?php

declare(strict_types=1);

namespace AV\JsonProvider\Validation;

use AV\JsonProvider\Schema\ColumnTypes;

/**
 * The five instant/wall-clock column kinds handled by TemporalCodec.
 *
 * The backing value equals the ColumnTypes base string, so tryFromBase() maps
 * a parsed column type directly onto a kind. year/month/day are deliberately
 * NOT here — they are plain integer parts validated by ValueValidator without
 * any DateTimeImmutable/timezone processing.
 *
 * Storage vs presentation:
 *  - Date, Time and TimeZ are wall-clock values with no absolute instant:
 *    stored verbatim, never timezone-shifted (a bare date or a bare
 *    wall-clock time shifted through UTC is not round-trip stable — it would
 *    drift under DST or return a different day/hour on the first read).
 *  - DateTime/DateTimeZ carry an absolute moment: stored in UTC, presented in
 *    the current PHP timezone.
 *
 * The `*z` kinds keep millisecond precision (format token `v`); the plain kinds
 * are second-resolution.
 */
enum TemporalKindEnum: string
{
    case Date = ColumnTypes::DATE;
    case Time = ColumnTypes::TIME;
    case TimeZ = ColumnTypes::TIMEZ;
    case DateTime = ColumnTypes::DATETIME;
    case DateTimeZ = ColumnTypes::DATETIMEZ;

    /**
     * Resolves a base type string ('date', 'datetime', ...) to a kind, or null
     * for any non-temporal base (int, string, year, month, day, ...).
     */
    public static function tryFromBase(string $base): self | null
    {
        return self::tryFrom($base);
    }

    /**
     * Whether the value carries a calendar date component.
     */
    public function hasDate(): bool
    {
        return match ($this) {
            self::Date, self::DateTime, self::DateTimeZ => true,
            self::Time, self::TimeZ                     => false,
        };
    }

    /**
     * Whether the value carries a wall-clock time component.
     */
    public function hasTime(): bool
    {
        return $this !== self::Date;
    }

    /**
     * Whether the canonical form keeps sub-second (millisecond) precision.
     */
    public function hasFraction(): bool
    {
        return $this === self::TimeZ || $this === self::DateTimeZ;
    }

    /**
     * Whether the value denotes an absolute moment and is therefore converted
     * between the local timezone and UTC on the way to and from storage. Only
     * DateTime/DateTimeZ do; Date, Time and TimeZ are stored verbatim
     * (wall-clock), because shifting a bare date or a bare time through UTC is
     * not round-trip stable.
     */
    public function shiftsTimezone(): bool
    {
        return $this === self::DateTime || $this === self::DateTimeZ;
    }

    /**
     * The canonical `DateTimeImmutable::format()` string for the stored (UTC)
     * and presented (local) forms.
     */
    public function canonicalFormat(): string
    {
        return match ($this) {
            self::Date      => 'Y-m-d',
            self::Time      => 'H:i:s',
            self::TimeZ     => 'H:i:s.v',
            self::DateTime  => 'Y-m-d H:i:s',
            self::DateTimeZ => 'Y-m-d H:i:s.v',
        };
    }
}
