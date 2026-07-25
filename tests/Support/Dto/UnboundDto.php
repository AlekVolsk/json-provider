<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

/**
 * Fixture without #[JsonProviderRecord]: registering it must fail because the
 * provider cannot know which table it maps to.
 */
final class UnboundDto
{
    public function __construct(
        public int $id,
    ) {
    }
}
