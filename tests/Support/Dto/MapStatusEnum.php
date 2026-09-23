<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

/**
 * The `status` column of the mapping fixture, as a backed enum. A DTO may bind
 * that column either as a plain `string` or through this enum — the difference
 * between the two bindings is exactly one `from()` call per row, which is what
 * the enum benchmark measures.
 */
enum MapStatusEnum: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
