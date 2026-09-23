<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * MapRowScalarDto with one binding changed: `status` arrives as MapStatusEnum
 * instead of `string`. The whole delta between the two is one enum `from()`
 * per row.
 */
#[JsonProviderRecord('map_rows')]
final class MapRowEnumDto
{
    public function __construct(
        public int $id,
        public string $sku,
        public int $categoryId,
        public MapStatusEnum $status,
        public int $bucket,
        public int $decile,
        public float $price,
        public int | null $qty,
        public string $title,
        public string | null $note,
        public bool $flag,
    ) {
    }
}
