<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture whose property `userName` derives the column `user_name`, which the
 * target schema does not declare — the compile error must name the property
 * and point at #[JsonProviderColumn].
 */
#[JsonProviderRecord('dto_derived_miss')]
final class DerivedMissDto
{
    public function __construct(
        public int $id,
        public int $userName,
    ) {}
}
