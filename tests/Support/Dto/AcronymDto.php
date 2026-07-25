<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture for acronym-aware name derivation: `userID` must derive the column
 * `user_id` (acronym boundary split) with no #[JsonProviderColumn] override,
 * and `httpStatus` must derive `http_status`.
 */
#[JsonProviderRecord('dto_acronym')]
final class AcronymDto
{
    public function __construct(
        public int $id,
        public int $userID,
        public int $httpStatus,
    ) {}
}
