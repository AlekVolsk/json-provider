<?php

declare(strict_types=1);

namespace AV\JsonProvider\Query;

/**
 * Sort direction.
 */
enum SortDirectionEnum: string
{
    case ASC = 'asc';
    case DESC = 'desc';
}
