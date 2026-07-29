<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Three plain scalars out of the thirteen columns of `map_rows` — the cheapest
 * hydration the mapper can do. A DTO maps only its constructor parameters, so
 * the omitted columns cost nothing on read.
 *
 * Baseline for the field-count ladder: narrow → scalar → enum → full.
 */
#[JsonProviderRecord('map_rows')]
final class MapRowNarrowDto
{
    public function __construct(
        public int $id,
        public string $sku,
        public float $price,
    ) {
    }
}
