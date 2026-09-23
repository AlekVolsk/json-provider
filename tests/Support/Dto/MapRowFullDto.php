<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * All thirteen columns of `map_rows`: MapRowEnumDto plus the two datetime
 * columns. Each of those costs a TemporalCodec parse into DateTimeImmutable
 * per row (the nullable one only when it is not null — three rows in four),
 * so the delta against MapRowEnumDto is the price of temporal mapping.
 */
#[JsonProviderRecord('map_rows')]
final class MapRowFullDto
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
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable | null $updatedAt,
        public string $title,
        public string | null $note,
        public bool $flag,
    ) {
    }
}
