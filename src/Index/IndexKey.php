<?php

declare(strict_types=1);

namespace AV\JsonProvider\Index;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;

/**
 * Builds compact, lexicographically sortable index keys (format v2).
 *
 * One part encodes one field value as a type tag plus payload (binary,
 * before direction is applied):
 *
 *   null         → 0x00
 *   bool false   → 0x01
 *   bool true    → 0x02
 *   int|float    → 0x03 + 8 bytes sortable double + 8 bytes sortable
 *                  residual — see below
 *   string       → 0x04 + escaped content + 0x00 terminator, escaping
 *                  0x00 → 0x01 0x01 and 0x01 → 0x01 0x02; no truncation
 *
 * Numbers share one tag so key(99) === key(99.0), matching the query
 * engine's numeric comparison and the float widening on read. The primary
 * 8 bytes are the IEEE 754 double reordered to sort as unsigned bytes;
 * the residual disambiguates ints beyond 2^53 that collapse onto the same
 * double (residual = value − integer value of that double, offset-binary
 * encoded). -0.0 is folded into 0.0 before packing. NAN/INF are not
 * indexable and throw.
 *
 * Every part is prefix-free (fixed length per tag; strings are terminated
 * and their content never holds the terminator byte), so a composite key
 * is the plain concatenation of parts with no separator and stays
 * injective. DESC inverts every byte of the whole encoded part (including
 * the string terminator, which becomes 0xFF and still terminates
 * unambiguously since escaped content never encodes to 0x00).
 *
 * The final key is bin2hex() of the concatenation: hex preserves both the
 * byte order under strcmp and prefix relations.
 */
final class IndexKey
{
    private const int EXACT_INT_BOUND = 2 ** 53;

    private function __construct() {}

    /**
     * Builds the composite index key for a record.
     *
     * @param array<string,null|scalar> $record
     */
    public static function build(array $record, IndexSchema $schema): string
    {
        $binary = '';

        foreach ($schema->fields as $fieldSchema) {
            $binary .= self::encodePart(
                $record[$fieldSchema->field] ?? null,
                $fieldSchema,
            );
        }

        return bin2hex($binary);
    }

    /**
     * Builds a single-part key from one value for first-component search.
     *
     * @param null|array{0:scalar,1:scalar}|scalar $value
     */
    public static function buildFromValue(
        mixed $value,
        IndexFieldSchema $fieldSchema,
    ): string {
        $scalar = \is_scalar($value) || $value === null ? $value : null;

        return bin2hex(self::encodePart($scalar, $fieldSchema));
    }

    /**
     * Key prefix of a numeric range bound: the tag and the sortable double
     * only, WITHOUT the residual bytes.
     *
     * The residual orders ints beyond 2^53 more finely than the engine's
     * `<=>` (which compares through the double cast), so a full-key bound
     * could cut off rows the comparator considers inside the range. A
     * range over this 9-byte prefix selects the whole equal-double run —
     * a superset in comparator terms — and the post-filter applies the
     * exact operator.
     */
    public static function numberBoundPrefix(
        float | int $value,
        IndexFieldSchema $fieldSchema,
    ): string {
        if (\is_float($value) && !is_finite($value)) {
            throw StorageException::indexKeyNonFinite($fieldSchema->field);
        }

        $double = (float)$value;

        if ($double === 0.0) {
            $double = 0.0;
        }

        $raw = "\x03" . self::sortableDouble($double);

        if ($fieldSchema->direction === SortDirectionEnum::DESC) {
            $raw = self::invertBytes($raw);
        }

        return bin2hex($raw);
    }

    private static function encodePart(
        bool | float | int | string | null $value,
        IndexFieldSchema $fieldSchema,
    ): string {
        if ($value === null) {
            $raw = "\x00";
        } elseif ($value === false) {
            $raw = "\x01";
        } elseif ($value === true) {
            $raw = "\x02";
        } elseif (\is_int($value) || \is_float($value)) {
            $raw = "\x03" . self::encodeNumber($fieldSchema->field, $value);
        } else {
            $raw = "\x04"
                . strtr($value, ["\x00" => "\x01\x01", "\x01" => "\x01\x02"])
                . "\x00";
        }

        if ($fieldSchema->direction === SortDirectionEnum::DESC) {
            $raw = self::invertBytes($raw);
        }

        return $raw;
    }

    /**
     * 16 sortable bytes for a number: the double approximation first, then
     * the integer residual for values a double cannot represent exactly.
     */
    private static function encodeNumber(
        string $field,
        float | int $value,
    ): string {
        if (\is_float($value) && !is_finite($value)) {
            throw StorageException::indexKeyNonFinite($field);
        }

        $double = (float)$value;

        if ($double === 0.0) {
            $double = 0.0;
        }

        $residual = 0;

        if (
            \is_int($value)
            && ($value > self::EXACT_INT_BOUND
                || $value < -self::EXACT_INT_BOUND)
        ) {
            $approx = $double >= (float)PHP_INT_MAX
                ? PHP_INT_MAX
                : (int)$double;
            $residual = $value - $approx;
        }

        return self::sortableDouble($double)
            . pack('J', $residual ^ PHP_INT_MIN);
    }

    /**
     * Encodes a double into 8 bytes whose unsigned byte order matches the
     * numeric order (IEEE 754 trick: negative values are bit-inverted,
     * non-negative get the sign bit flipped).
     */
    private static function sortableDouble(float $value): string
    {
        $packed = pack('E', $value);

        /** @var array{1: int} $bits */
        $bits = unpack('J', $packed);
        $i = $bits[1];

        if ($i < 0) {
            $i = ~$i;
        } else {
            $i ^= PHP_INT_MIN;
        }

        return pack('J', $i);
    }

    /**
     * Inverts every byte (XOR 0xFF) — reverses lexicographic order for desc.
     */
    private static function invertBytes(string $s): string
    {
        $result = '';
        $len = \strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $result .= \chr(255 - \ord($s[$i]));
        }

        return $result;
    }
}
