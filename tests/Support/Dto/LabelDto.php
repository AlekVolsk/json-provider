<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderColumn;
use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture exercising #[JsonProviderColumn]: property `text` maps to the
 * irregularly named column `label`.
 */
#[JsonProviderRecord('dto_labels')]
final class LabelDto
{
    public function __construct(
        public int $id,
        #[JsonProviderColumn('label')]
        public string $text,
    ) {}
}
