<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture for fraction handling on write: `at` maps to a second-precision
 * `datetime` column, `atz` to a millisecond-precision `datetimez` column.
 */
#[JsonProviderRecord('dto_stamps')]
final class StampDto
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $at,
        public \DateTimeImmutable $atz,
    ) {
    }
}
