<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture for DTO type diagnostics: an intersection-typed property must be
 * rejected with an "intersection types are not supported" message.
 */
#[JsonProviderRecord('dto_intersection')]
final class IntersectionPropertyDto
{
    public function __construct(
        public int $id,
        public \Countable & \Stringable $x,
    ) {}
}
