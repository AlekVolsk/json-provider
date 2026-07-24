<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

/**
 * Describes a relation between two tables.
 * Parsed from information_schema.json.
 *
 * Which side is the child (holds the FK column) depends on the relation
 * type: for belongsTo the declaring (from) table is the child; for
 * hasMany/hasOne the target (to) table is. The canonical resolution methods
 * childTable/childColumn/parentTable/parentColumn encapsulate that rule —
 * engine code must use them instead of reading fromTable/foreignKey
 * directly.
 */
final class RelationSchema
{
    /**
     * @param string $fromTable  declaring table (the child for belongsTo,
     *                           the parent for hasMany/hasOne)
     * @param string $foreignKey the FK column in the CHILD table (see
     *                           childTable())
     * @param string $toTable    target table (the parent for belongsTo,
     *                           the child for hasMany/hasOne)
     * @param string $references the referenced column in the PARENT table
     *                           (usually 'id')
     */
    public function __construct(
        public readonly string $fromTable,
        public readonly string $foreignKey,
        public readonly string $toTable,
        public readonly string $references,
        public readonly RelationTypeEnum $type,
        // phpcs:disable Generic.Files.LineLength
        public readonly ForeignKeyActionEnum $onDelete = ForeignKeyActionEnum::NO_ACTION,
        public readonly ForeignKeyActionEnum $onUpdate = ForeignKeyActionEnum::NO_ACTION,
        // phpcs:enable
    ) {}

    /**
     * The table that physically holds the FK column.
     */
    public function childTable(): string
    {
        return $this->type === RelationTypeEnum::BELONGS_TO
            ? $this->fromTable
            : $this->toTable;
    }

    /**
     * The FK column inside childTable().
     */
    public function childColumn(): string
    {
        return $this->foreignKey;
    }

    /**
     * The table whose rows are referenced by the FK.
     */
    public function parentTable(): string
    {
        return $this->type === RelationTypeEnum::BELONGS_TO
            ? $this->toTable
            : $this->fromTable;
    }

    /**
     * The referenced column inside parentTable() (usually 'id').
     */
    public function parentColumn(): string
    {
        return $this->references;
    }
}
