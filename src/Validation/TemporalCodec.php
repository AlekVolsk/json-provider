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
 *  - datetime   Y-m-d{ |T}H:i:s[Z|±HH:MM]
 *  - datetimez  Y-m-d{ |T}H:i:s[.fff][Z|±HH:MM]
 *
 * date, time and timez are stored VERBATIM (no timezone shift): a bare
 * calendar date or a bare wall-clock time has no absolute instant, and
 * anchoring it in the local zone to shift into UTC is not round-trip stable
 * (a date would return a different day on the first read; a time would drift
 * under DST). Only datetime/datetimez carry a moment and are stored in UTC.
 *
 * Sub-second precision policy: the second-resolution kinds (time, datetime)
 * reject any fraction; the millisecond kinds (timez, datetimez) accept 1..3
 * fractional digits and reject more — never silently truncating.
 */
final class TemporalCodec
{
    private const string DATE_RE = '/^(\d{4})-(\d{2})-(\d{2})$/';
    private const string DATETIME_RE = '/^(\d{4})-(\d{2})-(\d{2})[ T]'
        . '(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * The stored form is narrower than what encode() accepts: always a space
     * separator, never an offset suffix, at most milliseconds.
     */
    private const string STORED_DATETIME_RE = '/^(\d{4})-(\d{2})-(\d{2}) '
        . '(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/';

    /**
     * UTC never changes, and the local zone only when the script changes it,
     * so both are built once instead of per value. Decoding a table of a
     * hundred thousand rows with two datetime columns went through 400k
     * DateTimeZone constructions before this.
     */
    private static \DateTimeZone | null $utcZone = null;

    private static \DateTimeZone | null $localZone = null;

    private static string $localZoneName = '';

    /**
     * Application value -> canonical stored value (UTC for instant kinds).
     *
     * @throws TemporalParseException on any non-canonical or impossible input
     */
    public function encode(TemporalKind $kind, string $value): string
    {
        $utc = self::utcTz();

        if ($kind === TemporalKind::Date) {
            return $this->encodeDate($value, $utc);
        }

        if (!$kind->shiftsTimezone()) {
            return $this->encodeTime($kind, $value, $utc);
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
            $utc = self::utcTz();

            if ($kind === TemporalKind::Date) {
                return $this->reformatDate($value, $utc);
            }

            if (!$kind->shiftsTimezone()) {
                return $this->decodeTime($kind, $value, $utc);
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
     * The local canonical string (from decode()) -> DateTimeImmutable.
     * datetime/datetimez are returned in the current PHP timezone. date/time/
     * timez carry no instant, so only their wall-clock components are
     * meaningful; time/timez are anchored at a fixed 1970-01-01 UTC date so a
     * gap-hour wall-clock never drifts under DST. Throws on a non-canonical
     * value.
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
            return $this->parseLocalTime($kind, $value);
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

    /**
     * time/timez carry no instant: the local canonical string is the wall-clock
     * value itself. It is anchored to a fixed 1970-01-01 UTC date solely to
     * build a DateTimeImmutable — never to a "today"/local anchor, which would
     * roll a gap-hour wall-clock forward by an hour on a spring-forward DST day
     * (createFromFormat rolls it silently and getLastErrors() stays clean). The
     * caller reads the wall-clock back with format(); the anchor is inert.
     */
    private function parseLocalTime(
        TemporalKind $kind,
        string $value,
    ): \DateTimeImmutable {
        $re = $kind->hasFraction()
            ? '/^(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/'
            : '/^(\d{2}):(\d{2}):(\d{2})$/';

        if (preg_match($re, $value, $m) !== 1) {
            throw new TemporalParseException('bad local time');
        }

        $micro = $this->micros($m[4] ?? '');
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            "1970-01-01 {$m[1]}:{$m[2]}:{$m[3]}.{$micro}",
            self::utcTz(),
        );
        $this->assertClean($dt);

        return $dt;
    }

    private function parseLocalDateTime(
        TemporalKind $kind,
        string $value,
        \DateTimeZone $local,
    ): \DateTimeImmutable {
        if (preg_match(self::STORED_DATETIME_RE, $value, $m) !== 1) {
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

    /**
     * time/timez are stored VERBATIM (wall-clock, no timezone shift). The
     * fraction is captured with full width so the precision policy can reject
     * an unsupported value rather than the regex silently dropping it; a fixed
     * 1970-01-01 UTC anchor only drives H:i:s range validation and is never
     * part of the output.
     */
    private function encodeTime(
        TemporalKind $kind,
        string $value,
        \DateTimeZone $utc,
    ): string {
        if (
            preg_match(
                '/^(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?$/',
                $value,
                $m,
            ) !== 1
        ) {
            throw new TemporalParseException('expected time format');
        }

        $this->assertFractionAllowed($kind, $m[4] ?? '');

        $micro = $this->micros($m[4] ?? '');
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            "1970-01-01 {$m[1]}:{$m[2]}:{$m[3]}.{$micro}",
            $utc,
        );
        $this->assertClean($dt);

        return $dt->format($kind->canonicalFormat());
    }

    private function decodeTime(
        TemporalKind $kind,
        string $value,
        \DateTimeZone $utc,
    ): string {
        $re = $kind->hasFraction()
            ? '/^(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/'
            : '/^(\d{2}):(\d{2}):(\d{2})$/';

        if (preg_match($re, $value, $m) !== 1) {
            throw new TemporalParseException('bad stored time');
        }

        $micro = $this->micros($m[4] ?? '');
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            "1970-01-01 {$m[1]}:{$m[2]}:{$m[3]}.{$micro}",
            $utc,
        );
        $this->assertClean($dt);

        return $dt->format($kind->canonicalFormat());
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
        $this->assertFractionAllowed($kind, $m[7] ?? '');

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
        if (preg_match(self::STORED_DATETIME_RE, $value, $m) !== 1) {
            throw new TemporalParseException('bad stored datetime');
        }

        $micro = $this->micros($m[7] ?? '');
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}.{$micro}",
            $utc,
        );
        $this->assertClean($dt);

        /*
         * Stored values are already UTC, so when the script runs in UTC the
         * shift is a no-op — skipping it saves a timezone conversion per
         * value, and formatting still normalizes the fraction.
         */
        if ($local->getName() === $utc->getName()) {
            return $dt->format($kind->canonicalFormat());
        }

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
     * Enforces the sub-second precision policy by kind: a second-resolution
     * kind (time, datetime) rejects any fraction; a millisecond kind (timez,
     * datetimez) rejects more than three fractional digits. Never silently
     * truncates — an unsupported precision is a loud, dedicated error.
     */
    private function assertFractionAllowed(
        TemporalKind $kind,
        string $fraction,
    ): void {
        if (!$kind->hasFraction()) {
            if ($fraction !== '') {
                throw new TemporalParseException(
                    'sub-second precision not accepted',
                    fractionUnsupported: true,
                );
            }

            return;
        }

        if (\strlen($fraction) > 3) {
            throw new TemporalParseException(
                'sub-second precision exceeds milliseconds',
                fractionUnsupported: true,
            );
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
     * Rejects an offset beyond the ±14:00 IANA bound (symmetrically for both
     * signs) so an impossible zone like "+25:00" or "+00:99" fails loudly
     * instead of PHP rolling it over.
     */
    private function normalizeOffset(string $suffix): string
    {
        if ($suffix === 'Z') {
            return '+00:00';
        }

        $digits = str_replace(':', '', substr($suffix, 1));
        $hh = (int)substr($digits, 0, 2);
        $mm = (int)substr($digits, 2, 2);

        if ($mm > 59 || ($hh * 60 + $mm) > 14 * 60) {
            throw new TemporalParseException('offset out of range');
        }

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
        $name = date_default_timezone_get();

        if (self::$localZone === null || self::$localZoneName !== $name) {
            self::$localZone = new \DateTimeZone($name);
            self::$localZoneName = $name;
        }

        return self::$localZone;
    }

    private static function utcTz(): \DateTimeZone
    {
        return self::$utcZone ??= new \DateTimeZone('UTC');
    }
}
