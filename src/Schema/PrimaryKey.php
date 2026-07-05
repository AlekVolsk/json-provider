<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

/**
 * Primary key contract for JsonProvider.
 *
 * Field name and type are invariants, not settings. Every table must have
 * field `id` of type `int`, always first in `columns`, autoincrement.
 *
 * Used purely as a named place for constants so the magic literals `'id'`
 * and `'int'` are not scattered across the codebase. The PK type reuses
 * ColumnTypes::INT so the `'int'` literal lives in exactly one place.
 */
final class PrimaryKey
{
    public const string FIELD = 'id';
    public const string TYPE = ColumnTypes::INT;
}
