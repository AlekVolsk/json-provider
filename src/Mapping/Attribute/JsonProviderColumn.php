<?php

declare(strict_types=1);

namespace AV\JsonProvider\Mapping\Attribute;

/**
 * Overrides the column a DTO property maps to.
 *
 * Rarely needed: by default a property maps to the snake_case form of its name
 * (`createdAt` → `created_at`). Use this only for a genuinely irregular column
 * name that the naming convention cannot derive.
 *
 * ```php
 * public function __construct(
 *     #[JsonProviderColumn('legacy_col')] public string $title,
 * ) {}
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class JsonProviderColumn
{
    public function __construct(
        public readonly string $name,
    ) {}
}
