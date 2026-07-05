<?php

declare(strict_types=1);

namespace AV\JsonProvider\Validation;

/**
 * Converts temporal values between the application boundary and NDJSON storage.
 *
 * All arithmetic goes through DateTimeImmutable. The local timezone is read
 * from date_default_timezone_get() on every call, so the same DB read/written
 * from processes configured in different zones yields the same absolute moment
 * on disk (always UTC) and the caller's local wall-clock on the way out.
 *
 * encode() is strict — it accepts only the canonical system formats and raises
 * TemporalParseException on anything else (dots, slashes, reversed order, zero
 * dates, impossible dates). decode() is lenient — it never throws, returning
 * the raw value untouched if it cannot be parsed, so a legacy or hand-edited
 * file never breaks a read.
 *
 * Accepted encode() inputs:
 *  - date       Y-m-d
 *  - time       H:i:s
 *  - timez      H:i:s[.fff]
 *  - datetime   Y-m-d{ |T}H:i:s[.ffffff][Z|±HH:MM]
 *  - datetimez  same as datetime
 *
 * A bare date is stored verbatim (no timezone shift): a calendar date has no
 * instant, and anchoring it at local midnight to shift into UTC is not
 * round-trip stable (it would return a different day on the very first read).
 */
final class TemporalCodec
{
    private const string DATE_RE = '/^(\d{4})-(\d{2})-(\d{2})$/';
    private const string DATETIME_RE = '/^(\d{4})-(\d{2})-(\d{2})[ T]'
        . '(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * Application value -> canonical stored value (UTC for instant kinds).
     *
     * @throws TemporalParseException on any non-canonical or impossible input
     */
    public function encode(TemporalKind $kind, string $value): string
    {
        $utc = new \DateTimeZone('UTC');

        if ($kind === TemporalKind::Date) {
            return $this->encodeDate($value, $utc);
        }

        if (!$kind->hasDate()) {
            return $this->encodeTime($kind, $value, $this->localTz(), $utc);
        }

        return $this->encodeDateTime($kind, $value, $this->localTz(), $utc);
    }

    /**
     * Canonical stored value -> local presentation value. Never throws: an
     * unparseable stored value is returned as-is.
     */
    public function decode(TemporalKind $kind, string $value): string
    {
        try {
            $utc = new \DateTimeZone('UTC');

            if ($kind === TemporalKind::Date) {
                return $this->reformatDate($value, $utc);
            }

            if (!$kind->hasDate()) {
                return $this->decodeTime($kind, $value, $this->localTz(), $utc);
            }

            return $this->decodeDateTime($kind, $value, $this->localTz(), $utc);
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * DateTimeImmutable -> the local canonical string the array API works with.
     *
     * This is the DTO-facing counterpart to the string codec: it produces the
     * same local wall-clock form that decode() emits, so the value can flow
     * through the ordinary (UTC-encoding) write pipeline unchanged. A bare date
     * is formatted from the object as-is (no timezone shift).
     */
    public function dateTimeToLocalString(
        TemporalKind $kind,
        \DateTimeImmutable $value,
    ): string {
        if (!$kind->shiftsTimezone()) {
            return $value->format($kind->canonicalFormat());
        }

        return $value
            ->setTimezone($this->localTz())
            ->format($kind->canonicalFormat());
    }

    /**
     * The local canonical string (as produced by decode()) ->
     * DateTimeImmutable in the current PHP timezone. Throws
     * TemporalParseException on a value that is not in canonical form.
     *
     * @throws TemporalParseException
     */
    public function localStringToDateTime(
        TemporalKind $kind,
        string $value,
    ): \DateTimeImmutable {
        $local = $this->localTz();

        if ($kind === TemporalKind::Date) {
            return $this->parseLocalDate($value, $local);
        }

        if (!$kind->hasDate()) {
            return $this->parseLocalTime($kind, $value, $local);
        }

        return $this->parseLocalDateTime($kind, $value, $local);
    }

    private function parseLocalDate(
        string $value,
        \DateTimeZone $local,
    ): \DateTimeImmutable {
        if (preg_match(self::DATE_RE, $value) !== 1) {
            throw new TemporalParseException('bad local date');
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $local);
        $this->assertClean($dt);

        return $dt;
    }

    private function parseLocalTime(
        TemporalKind $kind,
        string $value,
        \DateTimeZone $local,
    ): \DateTimeImmutable {
        $re = $kind->hasFraction()
            ? '/^(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/'
            : '/^(\d{2}):(\d{2}):(\d{2})$/';

        if (preg_match($re, $value, $m) !== 1) {
            throw new TemporalParseException('bad local time');
        }

        $micro = $this->micros($m[4] ?? '');
        $anchor = (new \DateTimeImmutable('now', $local))->format('Y-m-d');
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            "{$anchor} {$m[1]}:{$m[2]}:{$m[3]}.{$micro}",
            $local,
        );
        $this->assertClean($dt);

        return $dt;
    }

    private function parseLocalDateTime(
        TemporalKind $kind,
        string $value,
        \DateTimeZone $local,
    ): \DateTimeImmutable {
        $re = '/^(\d{4})-(\d{2})-(\d{2}) '
            . '(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/';

        if (preg_match($re, $value, $m) !== 1) {
            throw new TemporalParseException('bad local datetime');
        }

        $micro = $this->micros($m[7] ?? '');
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}.{$micro}",
            $local,
        );
        $this->assertClean($dt);

        return $dt;
    }

    private function encodeDate(string $value, \DateTimeZone $utc): string
    {
        if (preg_match(self::DATE_RE, $value, $m) !== 1) {
            throw new TemporalParseException('expected date format Y-m-d');
        }

        $this->rejectZeroDate($m[2], $m[3]);

        return $this->reformatDate($value, $utc);
    }

    private function reformatDate(string $value, \DateTimeZone $utc): string
    {
        if (preg_match(self::DATE_RE, $value) !== 1) {
            throw new TemporalParseException('expected date format Y-m-d');
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
        $this->assertClean($dt);

        return $dt->format('Y-m-d');
    }

    private function encodeTime(
        TemporalKind $kind,
        string $value,
        \DateTimeZone $local,
        \DateTimeZone $utc,
    ): string {
        $re = $kind->hasFraction()
            ? '/^(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?$/'
            : '/^(\d{2}):(\d{2}):(\d{2})$/';

        if (preg_match($re, $value, $m) !== 1) {
            throw new TemporalParseException('expected time format');
        }

        $micro = $this->micros($m[4] ?? '');
        $anchor = (new \DateTimeImmutable('now', $local))->format('Y-m-d');
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            "{$anchor} {$m[1]}:{$m[2]}:{$m[3]}.{$micro}",
            $local,
        );
        $this->assertClean($dt);

        return $dt->setTimezone($utc)->format($kind->canonicalFormat());
    }

    private function decodeTime(
        TemporalKind $kind,
        string $value,
        \DateTimeZone $local,
        \DateTimeZone $utc,
    ): string {
        $re = $kind->hasFraction()
            ? '/^(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/'
            : '/^(\d{2}):(\d{2}):(\d{2})$/';

        if (preg_match($re, $value, $m) !== 1) {
            throw new TemporalParseException('bad stored time');
        }

        $micro = $this->micros($m[4] ?? '');
        $anchor = (new \DateTimeImmutable('now', $utc))->format('Y-m-d');
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            "{$anchor} {$m[1]}:{$m[2]}:{$m[3]}.{$micro}",
            $utc,
        );
        $this->assertClean($dt);

        return $dt->setTimezone($local)->format($kind->canonicalFormat());
    }

    private function encodeDateTime(
        TemporalKind $kind,
        string $value,
        \DateTimeZone $local,
        \DateTimeZone $utc,
    ): string {
        if (preg_match(self::DATETIME_RE, $value, $m) !== 1) {
            throw new TemporalParseException('expected datetime format');
        }

        $this->rejectZeroDate($m[2], $m[3]);

        $micro = $this->micros($m[7] ?? '');
        $base = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}.{$micro}";
        $suffix = $m[8] ?? '';

        if ($suffix !== '') {
            $dt = \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s.uP',
                $base . $this->normalizeOffset($suffix),
            );
        } else {
            $dt = \DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s.u',
                $base,
                $local,
            );
        }
        $this->assertClean($dt);

        return $dt->setTimezone($utc)->format($kind->canonicalFormat());
    }

    private function decodeDateTime(
        TemporalKind $kind,
        string $value,
        \DateTimeZone $local,
        \DateTimeZone $utc,
    ): string {
        $re = '/^(\d{4})-(\d{2})-(\d{2}) '
            . '(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/';

        if (preg_match($re, $value, $m) !== 1) {
            throw new TemporalParseException('bad stored datetime');
        }

        $micro = $this->micros($m[7] ?? '');
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}.{$micro}",
            $utc,
        );
        $this->assertClean($dt);

        return $dt->setTimezone($local)->format($kind->canonicalFormat());
    }

    /**
     * @param string $month two-digit month from the matched date
     * @param string $day   two-digit day from the matched date
     */
    private function rejectZeroDate(string $month, string $day): void
    {
        if ($month === '00' || $day === '00') {
            throw new TemporalParseException('zero date', zeroDate: true);
        }
    }

    /**
     * Normalizes a right-partial fractional string (1..6 digits) to a 6-digit
     * microsecond string. Empty input -> "000000".
     */
    private function micros(string $frac): string
    {
        if ($frac === '') {
            return '000000';
        }

        return str_pad(substr($frac, 0, 6), 6, '0');
    }

    /**
     * "Z" | "±HHMM" | "±HH:MM" -> "±HH:MM" (the form the P token parses).
     */
    private function normalizeOffset(string $suffix): string
    {
        if ($suffix === 'Z') {
            return '+00:00';
        }

        $digits = str_replace(':', '', substr($suffix, 1));

        return $suffix[0] . substr($digits, 0, 2) . ':' . substr($digits, 2, 2);
    }

    /**
     * Fails if createFromFormat returned false or flagged the value as invalid
     * (e.g. 2026-02-30, which PHP silently rolls over with a warning).
     *
     * @phpstan-assert \DateTimeImmutable $dt
     */
    private function assertClean(\DateTimeImmutable | false $dt): void
    {
        if ($dt === false) {
            throw new TemporalParseException('unparseable value');
        }

        $errors = \DateTimeImmutable::getLastErrors();

        if (
            $errors !== false
            && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
        ) {
            throw new TemporalParseException('invalid date/time value');
        }
    }

    private function localTz(): \DateTimeZone
    {
        return new \DateTimeZone(date_default_timezone_get());
    }
}
