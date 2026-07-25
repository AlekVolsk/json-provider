<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture for DTO type diagnostics: a union-typed property must be rejected
 * with a "union types are not supported" message.
 */
#[JsonProviderRecord('dto_union')]
final class UnionPropertyDto
{
    public function __construct(
        public int $id,
        public int | string $x,
    ) {}
}
