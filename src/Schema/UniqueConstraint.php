<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

use AV\JsonProvider\Exception\JsonProviderSchemaException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

/**
 * Unique constraint on one or more fields.
 * Checked automatically on insert/update.
 */
final class UniqueConstraint
{
    /**
     * @param string            $name   constraint name (used in error messages)
     * @param array<int,string> $fields one or more fields whose
     *                                  combination must be unique
     */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
    ) {
        if ($fields === []) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::UniqueConstraintFieldsEmpty,
                $name,
            );
        }
    }

    /**
     * Returns the uniqueness key string for the given record, or null when
     * the record does not participate in the constraint.
     *
     * SQL semantics: a record with null (or a missing field) in ANY
     * constraint field never conflicts — any number of such records may
     * coexist. Two records violate the constraint iff both keys are
     * non-null and byte-equal.
     *
     * Each part is length-prefixed, so the composite key is injective even
     * when a string value contains the "\x00" separator — distinct field
     * tuples can never produce equal keys.
     *
     * @param array<string,null|scalar> $record
     */
    public function keyOf(array $record): string | null
    {
        $parts = [];

        foreach ($this->fields as $field) {
            $value = $record[$field] ?? null;

            if ($value === null) {
                return null;
            }

            $part = self::keyPart($value);
            $parts[] = \strlen($part) . ':' . $part;
        }

        return implode("\x00", $parts);
    }

    /**
     * Canonical, type-tagged encoding of a single unique-key value.
     *
     * Values of different PHP types never collide: 1, 1.0, '1' and true
     * are four distinct keys (type-strict contract — no SQL-style numeric
     * merging). Stability across the int/float boundary is guaranteed by
     * the storage format (floats keep their fraction on disk), not by
     * merging the tags. %.17G canonicalizes floats so every float value
     * has exactly one representation; -0.0 is folded into 0.0 first,
     * because the query engine's strict `=` treats them as equal and the
     * unique key must follow that equality.
     */
    public static function keyPart(bool | float | int | string $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'b:1' : 'b:0';
        }

        if (\is_int($value)) {
            return 'n:' . $value;
        }

        if (\is_float($value)) {
            if ($value === 0.0) {
                $value = 0.0;
            }

            return 'f:' . \sprintf('%.17G', $value);
        }

        return 's:' . $value;
    }
}
