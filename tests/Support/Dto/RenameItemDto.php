<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture for renameColumn: bound to `rn_items` and referencing only the
 * `id` and `title` columns, so renaming an unreferenced column keeps the
 * map compilable while renaming `title` breaks it.
 */
#[JsonProviderRecord('rn_items')]
final class RenameItemDto
{
    public function __construct(
        public int $id,
        public string $title,
    ) {}
}
