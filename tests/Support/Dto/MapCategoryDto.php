<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * The parent side of the mapping fixture's belongsTo relation, used by the
 * two-step join benchmark: rows are fetched first, then their categories.
 */
#[JsonProviderRecord('map_categories')]
final class MapCategoryDto
{
    public function __construct(
        public int $id,
        public string $name,
        public int $sort,
    ) {
    }
}
