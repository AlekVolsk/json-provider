<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture registered for the `dto_phone_users` table: a not-null `email`
 * and a nullable `phone`.
 */
#[JsonProviderRecord('dto_phone_users')]
final class PhoneUserDto
{
    public function __construct(
        public int $id,
        public string $email,
        public string | null $phone,
    ) {
    }
}
