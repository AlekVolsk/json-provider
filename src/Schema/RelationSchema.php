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
 *
 * Table and column names are validated against IdentifierRules at
 * construction: relations are part of the schema boundary, and a name that
 * no table or column can legally carry signals file corruption, not a
 * migration path.
 *
 * backingIndex names the child-table index that serves FK existence
 * probes; it is provisioned by the relation DDL for relations whose
 * actions need probing (see needsBackingIndex) and may point either to a
 * service index (the reserved "_fk_" prefix) or to a reused single-column
 * user index on the FK column.
 */
final class RelationSchema
{
    /**
     * @param string      $fromTable    declaring table (the child for
     *                                  belongsTo, the parent for
     *                                  hasMany/hasOne)
     * @param string      $foreignKey   the FK column in the CHILD table
     *                                  (see childTable())
     * @param string      $toTable      target table (the parent for
     *                                  belongsTo, the child for
     *                                  hasMany/hasOne)
     * @param string      $references   the referenced column in the PARENT
     *                                  table (usually 'id')
     * @param null|string $backingIndex child-table index backing FK probes
     *                                  (null = none provisioned)
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
        public readonly string | null $backingIndex = null,
    ) {
        IdentifierRules::assertTableName($fromTable);
        IdentifierRules::assertTableName($toTable);
        IdentifierRules::assertColumnName($foreignKey);
        IdentifierRules::assertColumnName($references);
    }

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

    /**
     * Whether this relation requires a backing index on the child FK
     * column: cascade actions probe it to skip untouched children, and
     * restrict actions rely on it as the existence check itself. onUpdate
     * counts alongside onDelete — a restrict-on-update probe without a
     * backing index would dead-end in a configuration error.
     */
    public function needsBackingIndex(): bool
    {
        $probing = [
            ForeignKeyActionEnum::CASCADE,
            ForeignKeyActionEnum::RESTRICT,
        ];

        return \in_array($this->onDelete, $probing, true)
            || \in_array($this->onUpdate, $probing, true);
    }

    /**
     * Canonical identity of the FK edge: two declarations that resolve to
     * the same child table+column and parent table+column describe one
     * edge, whichever notation (belongsTo vs hasMany/hasOne) they use.
     */
    public function canonicalKey(): string
    {
        return $this->childTable() . '.' . $this->childColumn()
            . '->' . $this->parentTable() . '.' . $this->parentColumn();
    }

    /**
     * Returns a copy with the backing index replaced.
     */
    public function withBackingIndex(string | null $backingIndex): self
    {
        return new self(
            fromTable: $this->fromTable,
            foreignKey: $this->foreignKey,
            toTable: $this->toTable,
            references: $this->references,
            type: $this->type,
            onDelete: $this->onDelete,
            onUpdate: $this->onUpdate,
            backingIndex: $backingIndex,
        );
    }
}
