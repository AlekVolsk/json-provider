<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

/**
 * Action on referenced record change (delete/update).
 */
enum ForeignKeyActionEnum: string
{
    case NO_ACTION = 'noAction';
    case CASCADE = 'cascade';
    case SET_NULL = 'setNull';
    case RESTRICT = 'restrict';
}
