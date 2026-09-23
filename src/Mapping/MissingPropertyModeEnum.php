<?php

declare(strict_types=1);

namespace AV\JsonProvider\Mapping;

/**
 * What an object update does with a mapped column whose property the
 * object does not carry (not declared, or a typed property left
 * uninitialized). Objects are duck-typed: any object is accepted, only
 * its property set matters.
 *
 *  - WriteNull:  the column is written as null — the same rule insert
 *                applies, so a non-nullable column rejects it;
 *  - KeepStored: the column is left out of the write and keeps its
 *                stored value.
 */
enum MissingPropertyModeEnum
{
    case WriteNull;
    case KeepStored;
}
