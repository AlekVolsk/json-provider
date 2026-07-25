<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Fixture for DTO type diagnostics: an untyped promoted property must be
 * rejected with a "no type declaration" message, not a generic one.
 */
#[JsonProviderRecord('dto_untyped')]
final class UntypedPropertyDto
{
    /**
     * @param mixed $x deliberately untyped — the value under test
     */
    public function __construct(
        public int $id,
        public $x,
    ) {}
}
