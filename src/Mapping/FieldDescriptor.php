<?php

declare(strict_types=1);

namespace AV\JsonProvider\Mapping;

use AV\JsonProvider\Validation\TemporalKind;

/**
 * Compiled mapping for a single DTO field, produced once at registration.
 *
 * Runtime hydrate/extract read only these descriptors — no per-row reflection.
 * Exactly one of the shape flags is meaningful per field:
 *  - `temporalKind !== null` → column is date/time,
 *    property is DateTimeImmutable;
 *  - `enumClass !== null` → property is a BackedEnum stored
 *    by its backing value;
 *  - otherwise a plain scalar (`floatColumn` marks int→float widening on read).
 */
final class FieldDescriptor
{
    /**
     * @param null|class-string<\BackedEnum> $enumClass
     */
    public function __construct(
        public readonly string $property,
        public readonly string $column,
        public readonly bool $nullable,
        public readonly TemporalKind | null $temporalKind = null,
        public readonly string | null $enumClass = null,
        public readonly bool $floatColumn = false,
    ) {}
}
