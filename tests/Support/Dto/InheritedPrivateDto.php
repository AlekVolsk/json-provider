<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

/**
 * Binds to a table whose columns match InheritedPrivateBase but inherits the
 * base constructor — so its promoted `secret` is a parent private, invisible to
 * the class-scoped reader. compile() must reject it loudly instead of silently
 * losing the value. The extra accessor stands in for the real-world reason a
 * DTO subclasses another: to add behavior while inheriting the promoted fields.
 */
#[JsonProviderRecord('inh_priv')]
final class InheritedPrivateDto extends InheritedPrivateBase
{
    public function masked(): string
    {
        return $this->secret() === null ? '' : '***';
    }
}
