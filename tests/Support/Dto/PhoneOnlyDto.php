<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

/**
 * An unregistered object without the mapped not-null `email` property.
 */
final class PhoneOnlyDto
{
    public function __construct(
        public int $id,
        public string | null $phone,
    ) {
    }
}
