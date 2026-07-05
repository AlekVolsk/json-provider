<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

/**
 * Relation type between two tables.
 */
enum RelationTypeEnum: string
{
    case BELONGS_TO = 'belongsTo'; // N → 1 (foreign key in the current table)
    case HAS_MANY = 'hasMany';     // 1 → N (foreign key in the related table)
    case HAS_ONE = 'hasOne';       // 1 → 1 (foreign key in the related table)
}
