<?php

declare(strict_types=1);

namespace AV\JsonProvider\Mapping\Attribute;

/**
 * Binds a DTO class to a provider table.
 *
 * The only mandatory piece of mapping metadata: it names the table the DTO
 * maps to. Everything else (column names, types) is derived — names from the
 * property↔column naming convention, types from the table schema. The
 * attribute is pure metadata: it does not affect the class being `final`,
 * `readonly`, or immutable.
 *
 * ```php
 * #[JsonProviderRecord('users')]
 * final class User { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class JsonProviderRecord
{
    public function __construct(
        public readonly string $table,
    ) {}
}
