<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

/**
 * What separates a table's schema from a desired one: the columns, indexes
 * and unique constraints to add, drop or change. A report only — nothing
 * is applied.
 *
 *   addedColumns, droppedColumns — name => type; added ones in the desired
 *                                  order;
 *   retypedColumns               — name => the current and desired types;
 *   columnOrderChanged           — the columns both schemas have stand in
 *                                  another order;
 *   addedIndexes, droppedIndexes, addedUniqueConstraints,
 *   droppedUniqueConstraints     — as declared on the side they come from;
 *   changedIndexes,
 *   changedUniqueConstraints     — the current and the desired declaration.
 *
 * Columns are matched by name, indexes by name regardless of letter case
 * (as their files are), unique constraints by name. An index differs when
 * its name, its fields or their directions differ; a unique constraint
 * when its set of fields differs — the order of the fields does not
 * change what it constrains. The primary key index and the service
 * indexes of relations are left out on both sides: the engine owns them.
 * Comments are not compared.
 *
 * @phpstan-type ColumnChange array{current:string,desired:string}
 * @phpstan-type IndexChange array{
 *     current: IndexSchema,
 *     desired: IndexSchema
 * }
 * @phpstan-type UniqueChange array{
 *     current: UniqueConstraint,
 *     desired: UniqueConstraint
 * }
 */
final class TableDiff
{
    /**
     * @param array<string,string>       $addedColumns
     * @param array<string,string>       $droppedColumns
     * @param array<string,ColumnChange> $retypedColumns
     * @param list<IndexSchema>          $addedIndexes
     * @param list<IndexSchema>          $droppedIndexes
     * @param list<IndexChange>          $changedIndexes
     * @param list<UniqueConstraint>     $addedUniqueConstraints
     * @param list<UniqueConstraint>     $droppedUniqueConstraints
     * @param list<UniqueChange>         $changedUniqueConstraints
     */
    private function __construct(
        public readonly string $table,
        public readonly array $addedColumns,
        public readonly array $droppedColumns,
        public readonly array $retypedColumns,
        public readonly bool $columnOrderChanged,
        public readonly array $addedIndexes,
        public readonly array $droppedIndexes,
        public readonly array $changedIndexes,
        public readonly array $addedUniqueConstraints,
        public readonly array $droppedUniqueConstraints,
        public readonly array $changedUniqueConstraints,
    ) {
    }

    /**
     * The difference from $current to $desired.
     *
     * @internal
     */
    public static function between(
        TableSchema $current,
        TableSchema $desired,
    ): self {
        $retyped = [];

        foreach ($desired->columns as $column => $type) {
            $was = $current->columns[$column] ?? $type;

            if ($was !== $type) {
                $retyped[$column] = ['current' => $was, 'desired' => $type];
            }
        }

        $kept = array_keys(
            array_intersect_key($current->columns, $desired->columns),
        );
        $keptDesired = array_keys(
            array_intersect_key($desired->columns, $current->columns),
        );
        [$addedIndexes, $droppedIndexes, $changedIndexes] = self::compare(
            self::indexesOf($current),
            self::indexesOf($desired),
            self::sameIndex(...),
        );
        [$addedUnique, $droppedUnique, $changedUnique] = self::compare(
            self::constraintsOf($current),
            self::constraintsOf($desired),
            self::sameConstraint(...),
        );

        return new self(
            table: $current->name,
            addedColumns: array_diff_key($desired->columns, $current->columns),
            droppedColumns: array_diff_key(
                $current->columns,
                $desired->columns,
            ),
            retypedColumns: $retyped,
            columnOrderChanged: $kept !== $keptDesired,
            addedIndexes: $addedIndexes,
            droppedIndexes: $droppedIndexes,
            changedIndexes: $changedIndexes,
            addedUniqueConstraints: $addedUnique,
            droppedUniqueConstraints: $droppedUnique,
            changedUniqueConstraints: $changedUnique,
        );
    }

    /**
     * Whether the table already matches the desired schema.
     */
    public function isEmpty(): bool
    {
        return $this->addedColumns === []
            && $this->droppedColumns === []
            && $this->retypedColumns === []
            && !$this->columnOrderChanged
            && $this->addedIndexes === []
            && $this->droppedIndexes === []
            && $this->changedIndexes === []
            && $this->addedUniqueConstraints === []
            && $this->droppedUniqueConstraints === []
            && $this->changedUniqueConstraints === [];
    }

    /**
     * Items of $desired missing from $current, items of $current missing
     * from $desired, and the pairs present in both that differ.
     *
     * @template T
     *
     * @param array<string,T>    $current
     * @param array<string,T>    $desired
     * @param \Closure(T,T):bool $same
     *
     * @return array{list<T>, list<T>, list<array{current:T,desired:T}>}
     */
    private static function compare(
        array $current,
        array $desired,
        \Closure $same,
    ): array {
        $changed = [];

        foreach (array_intersect_key($desired, $current) as $key => $item) {
            if (!$same($current[$key], $item)) {
                $changed[] = ['current' => $current[$key], 'desired' => $item];
            }
        }

        return [
            array_values(array_diff_key($desired, $current)),
            array_values(array_diff_key($current, $desired)),
            $changed,
        ];
    }

    /**
     * The user indexes of a schema by the name of their files.
     *
     * @return array<string,IndexSchema>
     */
    private static function indexesOf(TableSchema $schema): array
    {
        $indexes = [];

        foreach ($schema->indexes as $index) {
            if (!$index->isPrimary && !$index->isService) {
                $indexes[IdentifierRules::physicalName($index->name)]
                    = $index;
            }
        }

        return $indexes;
    }

    /**
     * @return array<string,UniqueConstraint>
     */
    private static function constraintsOf(TableSchema $schema): array
    {
        $constraints = [];

        foreach ($schema->uniqueConstraints as $constraint) {
            $constraints[$constraint->name] = $constraint;
        }

        return $constraints;
    }

    private static function sameIndex(IndexSchema $a, IndexSchema $b): bool
    {
        return $a->name === $b->name
            && self::fieldsOf($a) === self::fieldsOf($b);
    }

    private static function sameConstraint(
        UniqueConstraint $a,
        UniqueConstraint $b,
    ): bool {
        return self::fieldSet($a) === self::fieldSet($b);
    }

    /**
     * @return list<array{string,string}>
     */
    private static function fieldsOf(IndexSchema $index): array
    {
        return array_map(
            static fn (IndexFieldSchema $f): array => [
                $f->field,
                $f->direction->value,
            ],
            array_values($index->fields),
        );
    }

    /**
     * @return list<string>
     */
    private static function fieldSet(UniqueConstraint $constraint): array
    {
        $fields = array_values(array_unique($constraint->fields));
        sort($fields);

        return $fields;
    }
}
