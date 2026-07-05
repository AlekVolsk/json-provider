<?php

declare(strict_types=1);

namespace AV\JsonProvider\Query;

/**
 * A single sort rule: field + direction.
 * Immutable value object.
 */
final class OrderBy
{
    public function __construct(
        public readonly string $field,
        public readonly SortDirectionEnum $direction = SortDirectionEnum::ASC,
    ) {}

    public static function asc(string $field): self
    {
        return new self($field, SortDirectionEnum::ASC);
    }

    public static function desc(string $field): self
    {
        return new self($field, SortDirectionEnum::DESC);
    }
}
