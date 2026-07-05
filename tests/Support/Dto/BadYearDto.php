<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Deliberately broken fixture: `year` is declared `string`, but the column is
 * an integer `year`. Registration must reject it (DTO_SCHEMA_MISMATCH).
 */
#[JsonProviderRecord('dto_events')]
final class BadYearDto
{
    public function __construct(
        public int $id,
        public string $year,
    ) {}
}
