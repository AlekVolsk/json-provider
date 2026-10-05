<?php

declare(strict_types=1);

namespace AV\JsonProvider\Engine;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Exception\JsonProviderSchemaException;
use AV\JsonProvider\Exception\JsonProviderTableException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Mapping\DtoMap;
use AV\JsonProvider\Mapping\DtoRegistry;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\ColumnDefaults;
use AV\JsonProvider\Schema\IdentifierRules;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Services\Format\TableFreshness;
use AV\JsonProvider\Storage\DerivedFiles;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\TableLockManager;

/**
 * Table and column DDL: creating, dropping and renaming tables,
 * migrating, reordering and renaming columns, table and column
 * comments.
 *
 * @internal
 */
final class SchemaChanges
{
    private readonly DerivedFiles $derived;
    private readonly DtoRegistry $dtoRegistry;
    private readonly TableFreshness $freshness;
    private readonly TableLockManager $locks;
    private readonly MetaRegistry $meta;
    private readonly NdjsonStorage $ndjson;
    private readonly SchemaRegistry $schema;

    public function __construct(
        private readonly Context $context,
        private readonly TableStore $store,
        private readonly IndexChanges $indexChanges,
    ) {
        $this->derived = $context->derived;
        $this->dtoRegistry = $context->dtoRegistry;
        $this->freshness = $context->freshness;
        $this->locks = $context->locks;
        $this->meta = $context->meta;
        $this->ndjson = $context->ndjson;
        $this->schema = $context->schema;
    }

    public function createTable(TableSchema $tableSchema): void
    {
        $this->context->assertWritable();

        $this->locks->withLocks(
            [$tableSchema->name => 'ex'],
            'ex',
            function () use ($tableSchema): void {
                $this->schema->reload();

                if (
                    !$this->schema->hasTable($tableSchema->name)
                    && $this->meta->hasEntry($tableSchema->name)
                ) {
                    $this->meta->dropEntry($tableSchema->name);
                }

                $this->schema->registerTable($tableSchema);
                $this->meta->initTable($tableSchema->name);

                $this->ndjson->createFileFresh(
                    $tableSchema->name,
                    $tableSchema->getFileName(),
                );

                foreach ($tableSchema->indexes as $index) {
                    $this->ndjson->createFileFresh(
                        $tableSchema->name,
                        $index->getFileName(),
                    );
                    $this->derived->setIndexBoundary(
                        $tableSchema->name,
                        $index->getFileName(),
                        $this->ndjson->pathOf(
                            $tableSchema->name,
                            $index->getFileName(),
                        ),
                        0,
                        0,
                    );
                }

                if ($this->freshness->stampsEnabled()) {
                    $this->meta->commitRewrite($tableSchema->name, 0, 0);
                }
            },
        );
    }

    public function dropTable(string $tableName): void
    {
        $this->context->assertWritable();

        IdentifierRules::assertTableName($tableName);

        $lockPlan = [$tableName => 'ex'];

        foreach ($this->schema->getAllRelations() as $relation) {
            if ($relation->parentTable() === $tableName) {
                $lockPlan[$relation->childTable()] ??= 'ex';
            }
        }

        $this->locks->withLocks(
            $lockPlan,
            'ex',
            function () use ($tableName): void {
                $this->schema->reload();

                if (!$this->schema->hasTable($tableName)) {
                    return;
                }

                $orphanedRelations = array_values(array_filter(
                    $this->schema->getAllRelations(),
                    static fn (RelationSchema $r): bool => $r
                        ->parentTable() === $tableName
                        && $r->childTable() !== $tableName,
                ));

                /*
                 * The cache entry must go while its version tag is still
                 * computable — after the meta entry and the data file are
                 * dropped, the tag degrades to a value that addresses
                 * nothing, and the warm entry of the last real state
                 * would survive the DROP.
                 */
                $this->store->invalidateCache($tableName);

                $this->schema->unregisterTable($tableName);

                foreach ($orphanedRelations as $relation) {
                    if (
                        !$this->locks->isHeld($relation->childTable(), 'ex')
                    ) {
                        continue;
                    }

                    $this->indexChanges->releaseServiceBacking($relation);
                }

                $this->meta->dropEntry($tableName);
                $this->ndjson->deleteTable($tableName);
                $this->derived->dropTable($tableName);
                $this->dtoRegistry->unregister($tableName);
                $this->locks->deleteTableLock($tableName);
            },
        );
    }

    public function renameTable(string $from, string $to): void
    {
        $this->context->assertWritable();

        IdentifierRules::assertTableName($from);
        IdentifierRules::assertTableName($to);

        $this->locks->withLocks(
            [$from => 'ex', $to => 'ex'],
            'ex',
            function () use ($from, $to): void {
                $this->schema->reload();

                $pending = $this->meta->getPendingRename();

                if ($pending !== null) {
                    throw new JsonProviderTableException(
                        JsonProviderErrorEn::RenameIncomplete,
                        $pending['from'],
                        $pending['to'],
                    );
                }

                if (!$this->schema->hasTable($from)) {
                    throw new JsonProviderTableException(
                        JsonProviderErrorEn::TableNotFound,
                        $from,
                    );
                }

                if (
                    $this->schema->hasTable($to)
                    || $this->meta->hasEntry($to)
                    || $this->ndjson->tableDirExists($to)
                ) {
                    throw new JsonProviderTableException(
                        JsonProviderErrorEn::TableAlreadyExists,
                        $to,
                    );
                }

                $tableSchema = $this->schema->getTable($from);

                /*
                 * While the old name's version tag is still computable:
                 * after the meta entry moves and the files rename, the
                 * tag degrades and the warm entry of the last real state
                 * would survive under the old name.
                 */
                $this->store->invalidateCache($from);

                $this->meta->setPendingRename($from, $to);

                $this->schema->mutate(
                    static function (
                        array $tables,
                        array $relations,
                    ) use (
                        $from,
                        $to
                    ): array {
                        $current = $tables[$from]
                            ?? throw new JsonProviderTableException(
                                JsonProviderErrorEn::TableNotFound,
                                $from,
                            );

                        unset($tables[$from]);
                        $tables[$to] = new TableSchema(
                            name: $to,
                            uniqueConstraints: $current->uniqueConstraints,
                            columns: $current->columns,
                            indexes: $current->indexes,
                            tableComment: $current->tableComment,
                            columnComment: $current->columnComment,
                        );

                        $updated = [];

                        foreach ($relations as $relation) {
                            $updated[] = self::renameTableInRelation(
                                $relation,
                                $from,
                                $to,
                            );
                        }

                        return [$tables, $updated];
                    },
                );

                $this->meta->moveEntry($from, $to);

                $this->ndjson->renameTableDir($from, $to);
                $this->derived->renameTable($from, $to);
                $this->ndjson->renameFile(
                    $to,
                    $tableSchema->getFileName(),
                    TableSchema::dataFileName($to),
                );

                $this->meta->clearPendingRename();

                $this->dtoRegistry->unregister($from);
                /*
                 * Defensive: a leftover entry of a PREVIOUS table that
                 * lived under $to could collide with the tag the renamed
                 * table now carries.
                 */
                $this->store->invalidateCache($to);
                $this->locks->deleteTableLock($from);
            },
        );
    }

    /**
     * @return array{added: list<string>, dropped: list<string>}
     */
    public function migrateColumns(TableSchema $desired): array
    {
        $this->context->assertWritable();

        return $this->locks->withLocks(
            [$desired->name => 'ex'],
            'ex',
            function () use ($desired): array {
                $this->schema->reload();
                $current = $this->schema->getTable($desired->name);

                $this->assertNoColumnTypeChange($current, $desired);

                $target = new TableSchema(
                    name: $current->name,
                    uniqueConstraints: $current->uniqueConstraints,
                    columns: $desired->columns,
                    indexes: $current->indexes,
                    tableComment: $current->tableComment,
                    columnComment: array_intersect_key(
                        $current->columnComment,
                        $desired->columns,
                    ),
                );

                $currentColumns = array_keys($current->columns);
                $desiredColumns = array_keys($desired->columns);

                if ($currentColumns === $desiredColumns) {
                    return ['added' => [], 'dropped' => []];
                }

                $added = array_values(
                    array_diff($desiredColumns, $currentColumns),
                );
                $dropped = array_values(
                    array_diff($currentColumns, $desiredColumns),
                );

                $this->assertDroppedColumnsFreeOfRelations(
                    $target->name,
                    $dropped,
                );
                $this->store->ensureTableConsistent($current);
                $records = $this->store->readAllForWrite($target->name);
                $this->store->assertRewritable($target->name);

                if ($records !== []) {
                    $this->assertAddedColumnsHaveDefault($target, $added);
                }

                $migrated = [];

                foreach ($records as $record) {
                    $row = [];

                    foreach ($target->columns as $column => $type) {
                        $row[$column] = \array_key_exists($column, $record)
                            ? $record[$column]
                            : ColumnDefaults::forType($type);
                    }

                    $migrated[] = $row;
                }

                $this->ndjson->encodeRecords($target->name, $migrated);

                $this->store->createMissingIndexFiles($target);
                $this->schema->replaceTable($target);
                $this->store->writeAll($target->name, $target, $migrated);

                return ['added' => $added, 'dropped' => $dropped];
            },
        );
    }

    /**
     * @param array<int,string> $newOrder
     */
    public function reorderColumns(string $tableName, array $newOrder): void
    {
        $this->context->assertWritable();

        $this->locks->withLocks(
            [$tableName => 'ex'],
            'ex',
            function () use ($tableName, $newOrder): void {
                $this->schema->reload();
                $tableSchema = $this->schema->getTable($tableName);

                $this->validateNewOrder($tableSchema, $newOrder);

                $normalized = $this->normalizeNewOrder($newOrder);

                if (\count($normalized) !== \count($tableSchema->columns)) {
                    $missing = array_diff(
                        array_keys($tableSchema->columns),
                        $normalized,
                    );

                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::ReorderColumnsIncomplete,
                        $tableName,
                        implode(', ', $missing),
                    );
                }

                $newColumns = [];

                foreach ($normalized as $field) {
                    $newColumns[$field] = $tableSchema->columns[$field];
                }

                $newSchema = new TableSchema(
                    name: $tableSchema->name,
                    uniqueConstraints: $tableSchema->uniqueConstraints,
                    columns: $newColumns,
                    indexes: $tableSchema->indexes,
                    tableComment: $tableSchema->tableComment,
                    columnComment: $tableSchema->columnComment,
                );

                $this->store->ensureTableConsistent($tableSchema);
                $records = $this->store->readAllForWrite($tableName);
                $this->store->assertRewritable($tableName);

                $this->ndjson->encodeRecords($tableName, array_map(
                    fn (array $r): array => $this->store->normalizeRecord(
                        $newSchema,
                        $r,
                    ),
                    $records,
                ));

                $this->schema->replaceTable($newSchema);
                $this->store->writeAll($tableName, $newSchema, $records);
            },
        );
    }

    public function renameColumn(
        string $tableName,
        string $from,
        string $to,
    ): void {
        $this->context->assertWritable();

        $this->locks->withLocks(
            [$tableName => 'ex'],
            'ex',
            function () use ($tableName, $from, $to): void {
                $this->schema->reload();
                $tableSchema = $this->schema->getTable($tableName);

                if (!isset($tableSchema->columns[$from])) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::ColumnNotFound,
                        $tableName,
                        $from,
                    );
                }

                if ($from === PrimaryKey::FIELD) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::PkColumnNotRenamable,
                        $tableName,
                    );
                }

                IdentifierRules::assertColumnName($to);

                if (isset($tableSchema->columns[$to])) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::ColumnAlreadyExists,
                        $tableName,
                        $to,
                    );
                }

                $newSchema = self::renameColumnInSchema(
                    $tableSchema,
                    $from,
                    $to,
                );

                $this->store->ensureTableConsistent($tableSchema);
                $records = $this->store->readAllForWrite($tableName);
                $this->store->assertRewritable($tableName);

                $renamed = [];

                foreach ($records as $record) {
                    $row = [];

                    foreach ($record as $key => $value) {
                        $row[$key === $from ? $to : $key] = $value;
                    }

                    $renamed[] = $row;
                }

                $this->ndjson->encodeRecords($tableName, array_map(
                    fn (array $r): array => $this->store->normalizeRecord(
                        $newSchema,
                        $r,
                    ),
                    $renamed,
                ));

                $this->schema->mutate(
                    static function (
                        array $tables,
                        array $relations,
                    ) use (
                        $tableName,
                        $from,
                        $to,
                        $newSchema,
                    ): array {
                        if (!isset($tables[$tableName])) {
                            throw new JsonProviderTableException(
                                JsonProviderErrorEn::TableNotFound,
                                $tableName,
                            );
                        }

                        $tables[$tableName] = $newSchema;

                        $updated = [];

                        foreach ($relations as $relation) {
                            $renamedRelation = self::renameColumnInRelation(
                                $relation,
                                $tableName,
                                $from,
                                $to,
                            );

                            $oldBacking
                                = IdentifierRules::serviceIndexNameFor($from);
                            $newBacking
                                = IdentifierRules::serviceIndexNameFor($to);

                            if (
                                $renamedRelation->childTable() === $tableName
                                && $renamedRelation
                                    ->backingIndex === $oldBacking
                            ) {
                                $renamedRelation = $renamedRelation
                                    ->withBackingIndex($newBacking);
                            }

                            $updated[] = $renamedRelation;
                        }

                        return [$tables, $updated];
                    },
                );

                $this->store->createMissingIndexFiles($newSchema);
                $this->store->writeAll($tableName, $newSchema, $renamed);

                /*
                 * writeAll has already built the index files of the NEW
                 * schema (including a renamed service backing); the file
                 * under the old service name is now an undeclared
                 * leftover — the same shape repair would sweep as an
                 * orphan, removed eagerly here.
                 */
                $oldServiceFile = IndexSchema::fileNameFor(
                    IdentifierRules::serviceIndexNameFor($from),
                );
                $this->ndjson->deleteFile($tableName, $oldServiceFile);
                $this->recompileDto($tableName, $newSchema);
            },
        );
    }

    public function setTableComment(
        string $tableName,
        string | null $comment,
    ): void {
        $this->context->assertWritable();

        $this->schema->updateTable(
            $tableName,
            static fn (TableSchema $t): TableSchema => $t
                ->withTableComment($comment),
        );
    }

    public function setColumnComment(
        string $tableName,
        string $column,
        string | null $comment,
    ): void {
        $this->context->assertWritable();

        $this->schema->updateTable(
            $tableName,
            static function (TableSchema $t) use (
                $tableName,
                $column,
                $comment,
            ): TableSchema {
                if (!isset($t->columns[$column])) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::ColumnNotFound,
                        $tableName,
                        $column,
                    );
                }

                return $t->withColumnComment($column, $comment);
            },
        );
    }

    /**
     * @param array<string,string> $comments column name => description
     */
    public function setColumnComments(
        string $tableName,
        array $comments,
        bool $merge = false,
    ): void {
        $this->context->assertWritable();

        $this->schema->updateTable(
            $tableName,
            static function (TableSchema $t) use (
                $tableName,
                $comments,
                $merge,
            ): TableSchema {
                foreach (array_keys($comments) as $column) {
                    if (!isset($t->columns[$column])) {
                        throw new JsonProviderSchemaException(
                            JsonProviderErrorEn::ColumnNotFound,
                            $tableName,
                            $column,
                        );
                    }
                }

                $effective = $merge
                    ? array_merge($t->columnComment, $comments)
                    : $comments;

                return $t->withColumnComments($effective);
            },
        );
    }

    /**
     * A migration may not drop a column that is a side of a declared
     * relation (the FK column of its child or the referenced column of
     * its parent): a setNull edge has no backing index to block the drop
     * and would leave every parent delete failing with
     * RELATION_COLUMN_NOT_FOUND. Drop the relation first.
     *
     * @param array<int,string> $dropped
     */
    private function assertDroppedColumnsFreeOfRelations(
        string $tableName,
        array $dropped,
    ): void {
        if ($dropped === []) {
            return;
        }

        foreach ($this->schema->getAllRelations() as $relation) {
            foreach ($dropped as $column) {
                $isChildSide = $relation->childTable() === $tableName
                    && $relation->childColumn() === $column;
                $isParentSide = $relation->parentTable() === $tableName
                    && $relation->parentColumn() === $column;

                if ($isChildSide || $isParentSide) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::MigrateFieldUnknownColumnRelation,
                        $tableName,
                        $relation->fromTable,
                        $relation->foreignKey,
                        $relation->toTable,
                        $column,
                    );
                }
            }
        }
    }

    /**
     * Rewrites a relation after a table rename: both sides are checked —
     * a relation may reference the renamed table as its from- and
     * to-table at once (self-reference).
     */
    private static function renameTableInRelation(
        RelationSchema $relation,
        string $from,
        string $to,
    ): RelationSchema {
        if ($relation->fromTable !== $from && $relation->toTable !== $from) {
            return $relation;
        }

        return new RelationSchema(
            fromTable: $relation->fromTable === $from
                ? $to
                : $relation->fromTable,
            foreignKey: $relation->foreignKey,
            toTable: $relation->toTable === $from ? $to : $relation->toTable,
            references: $relation->references,
            type: $relation->type,
            onDelete: $relation->onDelete,
            onUpdate: $relation->onUpdate,
            backingIndex: $relation->backingIndex,
        );
    }

    /**
     * Builds the table descriptor with one column renamed: the column key
     * keeps its position and type; indexes, unique constraints and the
     * column comment map follow the rename. A service FK backing index of
     * the renamed column follows with its NAME ("_fk_<from>" becomes
     * "_fk_<to>") — the name encodes the column, and keeping the old one
     * would collide with a later backing provision for a new column named
     * like the old one.
     */
    private static function renameColumnInSchema(
        TableSchema $tableSchema,
        string $from,
        string $to,
    ): TableSchema {
        $columns = [];

        foreach ($tableSchema->columns as $column => $type) {
            $columns[$column === $from ? $to : $column] = $type;
        }

        $constraints = [];

        foreach ($tableSchema->uniqueConstraints as $constraint) {
            $constraints[] = new UniqueConstraint(
                $constraint->name,
                array_map(
                    static fn (string $f): string => $f === $from ? $to : $f,
                    $constraint->fields,
                ),
            );
        }

        $indexes = [];

        foreach ($tableSchema->indexes as $index) {
            $fields = [];

            foreach ($index->fields as $field) {
                $fields[] = new IndexFieldSchema(
                    $field->field === $from ? $to : $field->field,
                    $field->direction,
                );
            }

            $name = $index->name;

            if (
                $index->isService
                && $name === IdentifierRules::serviceIndexNameFor($from)
            ) {
                $name = IdentifierRules::serviceIndexNameFor($to);
            }

            $indexes[] = new IndexSchema(
                $name,
                $fields,
                $index->isPrimary,
                $index->isService,
            );
        }

        $comments = [];

        foreach ($tableSchema->columnComment as $column => $text) {
            $comments[$column === $from ? $to : $column] = $text;
        }

        return new TableSchema(
            name: $tableSchema->name,
            uniqueConstraints: $constraints,
            columns: $columns,
            indexes: $indexes,
            tableComment: $tableSchema->tableComment,
            columnComment: $comments,
        );
    }

    /**
     * Rewrites a relation after a column rename: the FK column is matched
     * on the relation's CHILD side and the referenced column on its PARENT
     * side (canonical resolution — for belongsTo the declaring table is
     * the child, for hasMany/hasOne the target table is). A relation not
     * touching the renamed column is returned as-is; a self-referencing
     * relation may have both sides renamed at once.
     */
    private static function renameColumnInRelation(
        RelationSchema $relation,
        string $tableName,
        string $from,
        string $to,
    ): RelationSchema {
        $foreignKey = $relation->foreignKey;
        $references = $relation->references;

        if (
            $relation->childTable() === $tableName
            && $relation->childColumn() === $from
        ) {
            $foreignKey = $to;
        }

        if (
            $relation->parentTable() === $tableName
            && $relation->parentColumn() === $from
        ) {
            $references = $to;
        }

        if (
            $foreignKey === $relation->foreignKey
            && $references === $relation->references
        ) {
            return $relation;
        }

        return new RelationSchema(
            fromTable: $relation->fromTable,
            foreignKey: $foreignKey,
            toTable: $relation->toTable,
            references: $references,
            type: $relation->type,
            onDelete: $relation->onDelete,
            onUpdate: $relation->onUpdate,
            backingIndex: $relation->backingIndex,
        );
    }

    /**
     * Recompiles the DTO map bound to the table against a changed schema.
     * A DTO that no longer matches (e.g. its property still maps to a
     * renamed column) is unbound instead: object reads then fail loudly
     * with DTO_NOT_REGISTERED rather than hydrating garbage.
     */
    private function recompileDto(
        string $tableName,
        TableSchema $tableSchema,
    ): void {
        $map = $this->dtoRegistry->forTable($tableName);

        if ($map === null) {
            return;
        }

        try {
            $this->dtoRegistry->register(
                DtoMap::compile($map->class, $tableSchema),
            );
        } catch (JsonProviderException) {
            $this->dtoRegistry->unregister($tableName);
        }
    }

    /**
     * Rejects a migration that would change the type of a column kept in both
     * the current and desired schema: migrateColumns rewrites data verbatim and
     * never re-encodes values, so a type change is out of its contract.
     */
    private function assertNoColumnTypeChange(
        TableSchema $current,
        TableSchema $desired,
    ): void {
        foreach ($desired->columns as $column => $type) {
            $currentType = $current->columns[$column] ?? null;

            if ($currentType !== null && $currentType !== $type) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::MigrateColumnTypeChange,
                    $desired->name,
                    $column,
                    $currentType,
                    $type,
                );
            }
        }
    }

    /**
     * Rejects adding a not-null column with no zero-value default to a table
     * that already holds rows: those rows would otherwise be filled with an
     * invalid value (null, or an out-of-range 0 for month/day).
     *
     * @param list<string> $added
     */
    private function assertAddedColumnsHaveDefault(
        TableSchema $desired,
        array $added,
    ): void {
        foreach ($added as $column) {
            $type = $desired->columns[$column];

            if (!ColumnDefaults::hasSafeDefault($type)) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::MigrateColumnNoDefault,
                    $desired->name,
                    $column,
                    $type,
                );
            }
        }
    }

    /**
     * Validates a column reorder request:
     *  - every name must exist in the current schema;
     *  - no duplicates allowed.
     *
     * @param array<int,string> $newOrder
     */
    private function validateNewOrder(
        TableSchema $tableSchema,
        array $newOrder,
    ): void {
        $seen = [];

        foreach ($newOrder as $field) {
            if (!isset($tableSchema->columns[$field])) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::ReorderColumnsUnknown,
                    $tableSchema->name,
                    $field,
                );
            }

            if (isset($seen[$field])) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::ReorderColumnsDuplicate,
                    $tableSchema->name,
                    $field,
                );
            }

            $seen[$field] = true;
        }
    }

    /**
     * Normalizes a column reorder request: enforces id at position 0 (prepends
     * if missing, moves to front if not first). Returns the resulting list.
     *
     * @param array<int,string> $newOrder
     *
     * @return array<int,string>
     */
    private function normalizeNewOrder(array $newOrder): array
    {
        $withoutId = array_values(array_filter(
            $newOrder,
            static fn (string $f): bool => $f !== PrimaryKey::FIELD,
        ));

        return array_merge([PrimaryKey::FIELD], $withoutId);
    }
}
