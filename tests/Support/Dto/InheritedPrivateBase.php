<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

/**
 * A non-DTO parent whose constructor promotes a PRIVATE property. A child DTO
 * that inherits this constructor cannot read the private through the mapper's
 * class-scoped reader, so binding it must be rejected loudly at compile time
 * rather than silently writing null.
 */
class InheritedPrivateBase
{
    public function __construct(
        public int $id,
        private string | null $secret = null,
    ) {}

    public function secret(): string | null
    {
        return $this->secret;
    }
}
