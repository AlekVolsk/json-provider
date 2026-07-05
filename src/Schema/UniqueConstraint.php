<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

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
    ) {}

    /**
     * Returns the uniqueness key string for the given record.
     *
     * @param array<string,null|scalar> $record
     */
    public function keyOf(array $record): string
    {
        $parts = [];

        foreach ($this->fields as $field) {
            $parts[] = (string)($record[$field] ?? '');
        }

        return implode("\x00", $parts);
    }
}
