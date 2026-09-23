<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

/**
 * An unregistered look-alike of PhoneUserDto: it carries `email` but names
 * the phone property `mobile`, so the mapped `phone` property is missing.
 */
final class ContactDto
{
    public function __construct(
        public int $id,
        public string $email,
        public string | null $mobile,
    ) {
    }
}
