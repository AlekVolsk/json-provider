<?php

declare(strict_types=1);

namespace AV\JsonProvider\Mapping;

/**
 * The default DTO-property ↔ column-name convention.
 *
 * DTO properties follow the PHP coding-standard `camelCase`; database columns
 * follow the `snake_case` default. This bridges the two automatically so no
 * per-field attribute is needed for the common case:
 * `createdAt` → `created_at`, `id` → `id`.
 */
final class NameStrategy
{
    /**
     * Derives the column name from a DTO property name
     * (camelCase → snake_case).
     */
    public static function columnFor(string $property): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $property);

        return strtolower($snake ?? $property);
    }
}
