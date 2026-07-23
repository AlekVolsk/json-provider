<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * DTO bound to the FloatRoundtripTest 'floats' table (id/name/price):
 * proves the DTO hydration path surfaces the same PHP float types as the
 * array select path once float columns are widened on read.
 */
#[JsonProviderRecord('floats')]
final class FloatPriceDto
{
    public function __construct(
        public int $id,
        public string $name,
        public float $price,
    ) {}
}
