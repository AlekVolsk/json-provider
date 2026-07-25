<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture for any-visibility extraction: promoted constructor properties of
 * every visibility (public, protected, private) must map both ways. The
 * non-nullable private `note` with a non-empty value must survive extract
 * without a false NULL_NOT_ALLOWED. Getters expose the private/protected
 * state for assertions.
 */
#[JsonProviderRecord('dto_visibility')]
final class VisibilityDto
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
