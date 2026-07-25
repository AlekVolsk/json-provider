<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

use AV\JsonProvider\Query\SortDirectionEnum;

/**
 * A single index field with its sort direction.
 */
final class IndexFieldSchema
{
    public function __construct(
        public readonly string $field,
        public readonly SortDirectionEnum $direction,
    ) {
    }
}
