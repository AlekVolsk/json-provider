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
 *
 * Acronym runs are split at the boundary with the following word, so an
 * all-caps prefix or an embedded acronym does not collapse into one token:
 * `HTTPStatus` → `http_status`, `userID` → `user_id`, `APIKey` → `api_key`.
 * When the derived name does not match a column, bind the property with
 * #[JsonProviderColumn("...")] — the standard escape hatch.
 */
final class NameStrategy
{
    /**
     * Derives the column name from a DTO property name
     * (camelCase / acronym-aware → snake_case).
     */
    public static function columnFor(string $property): string
    {
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $property);
        $s = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $s ?? $property);

        return strtolower($s ?? $property);
    }
}
