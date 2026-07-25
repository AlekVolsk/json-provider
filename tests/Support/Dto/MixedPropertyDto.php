<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture for DTO type diagnostics: a `mixed` property must be rejected with
 * a message about mixed, reported before the nullability check.
 */
#[JsonProviderRecord('dto_mixed')]
final class MixedPropertyDto
{
    public function __construct(
        public int $id,
        public mixed $x,
    ) {
    }
}
