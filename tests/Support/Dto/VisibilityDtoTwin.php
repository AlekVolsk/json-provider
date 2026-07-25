<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * A second, structurally identical DTO bound to the SAME table as
 * VisibilityDto — the partner for the one-DTO-per-table collision test.
 */
#[JsonProviderRecord('dto_visibility')]
final class VisibilityDtoTwin
{
    public function __construct(
        public int $id,
        private string $note,
        private string $tag,
    ) {
    }

    public function note(): string
    {
        return $this->note;
    }

    public function tag(): string
    {
        return $this->tag;
    }
}
