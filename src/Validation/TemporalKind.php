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
 *  - Date is a bare calendar date with no instant: stored verbatim, never
 *    timezone-shifted (shifting it is not round-trip stable).
 *  - Time/TimeZ/DateTime/DateTimeZ carry a moment: stored in UTC, presented in
 *    the current PHP timezone.
 *
 * The `*z` kinds keep millisecond precision (format token `v`); the plain kinds
 * are second-resolution.
 */
enum TemporalKind: string
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
     * Whether the value denotes a moment and is therefore converted between the
     * local timezone and UTC. A bare Date is stored verbatim (false).
     */
    public function shiftsTimezone(): bool
    {
        return $this !== self::Date;
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
