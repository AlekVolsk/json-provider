<?php

declare(strict_types=1);

namespace AV\JsonProvider\Index;

use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;

/**
 * Utility for building compact, lexicographically sortable index keys.
 *
 * Encoding (all results are safe for JSON, no bin2hex wrapping):
 *   null        → "--"
 *   bool false  → "0"
 *   bool true   → "1"
 *   int         → sprintf('%08x', value + 0x80000000) — 8 hex chars,
 *                 signed-safe
 *   float       → sortable IEEE 754 trick — 16 hex chars
 *   string      → as-is (truncated to 64 chars for index sanity)
 *
 * Composite key: fields joined with "|".
 * For desc direction every char is inverted (XOR 0xFF on each byte,
 * then bin2hex).
 */
final class IndexKey
{
    private function __construct() {}

    /**
     * Builds the index key for a record.
     *
     * @param array<string,null|scalar> $record
     */
    public static function build(array $record, IndexSchema $schema): string
    {
        $parts = [];

        foreach ($schema->fields as $fieldSchema) {
            $parts[] = self::encodeValue(
                $record[$fieldSchema->field] ?? null,
                $fieldSchema->direction,
            );
        }

        return implode('|', $parts);
    }

    /**
     * Builds a key from a single value for search.
     *
     * @param null|array{0:scalar,1:scalar}|scalar $value
     */
    public static function buildFromValue(
        mixed $value,
        IndexFieldSchema $fieldSchema,
    ): string {
        $scalar = \is_scalar($value) || $value === null ? $value : null;

        return self::encodeValue($scalar, $fieldSchema->direction);
    }

    private static function encodeValue(
        bool | float | int | string | null $value,
        SortDirectionEnum $direction,
    ): string {
        if ($value === null) {
            $raw = "\x00";
        } elseif ($value === false) {
            $raw = "\x01";
        } elseif ($value === true) {
            $raw = "\x02";
        } elseif (\is_int($value) || \is_float($value)) {
            $raw = self::encodeFloatBinary($value);
        } else {
            $raw = \strlen($value) > 32 ? substr($value, 0, 32) : $value;
        }

        if ($direction === SortDirectionEnum::DESC) {
            $raw = self::invertBytes($raw);
        }

        return bin2hex($raw);
    }

    /**
     * Encodes float into 8 sortable bytes (IEEE 754 trick).
     */
    private static function encodeFloatBinary(float $value): string
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
