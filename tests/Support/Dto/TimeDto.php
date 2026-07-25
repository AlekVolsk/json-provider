<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture for the DTO object path over a verbatim `time` column: the
 * `atTime` property maps to the `at_time` column and must round-trip the
 * wall-clock time unchanged (no timezone shift) through
 * DateTimeImmutable ↔ stored string.
 */
#[JsonProviderRecord('dto_time')]
final class TimeDto
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $atTime,
    ) {}
}
