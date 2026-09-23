<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support\Dto;

/**
 * String-backed enum fixture: stored as its backing value, hydrated via from().
 */
enum EventStatusEnum: string
{
    case Active = 'active';
    case Done = 'done';
}
