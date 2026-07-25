<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * DTO for the load-benchmark rows. Bound to the first load table (`load_0`),
 * which every query benchmark targets; it mirrors the LoadFixture schema
 * (id/name/val/price/flag) so the object path can be measured against the
 * array path at scale.
 */
#[JsonProviderRecord('load_0')]
final class LoadRowDto
{
    public function __construct(
        public int $id,
        public string $name,
        public int $val,
        public float $price,
        public bool $flag,
    ) {
    }
}
