<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Every non-temporal column of `map_rows`, all bound as plain scalars —
 * `status` included, as a `string` rather than as MapStatusEnum. Against
 * MapRowNarrowDto it isolates the cost of field COUNT; against MapRowEnumDto,
 * which differs from it in that single binding, it isolates the cost of enum
 * conversion.
 *
 * `categoryId` and `qty` also exercise the camelCase → snake_case name
 * strategy (category_id, qty).
 */
#[JsonProviderRecord('map_rows')]
final class MapRowScalarDto
{
    public function __construct(
        public int $id,
        public string $sku,
        public int $categoryId,
        public string $status,
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
