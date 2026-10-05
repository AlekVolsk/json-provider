<?php

declare(strict_types=1);

namespace AV\JsonProvider\Engine;

use AV\JsonProvider\Exception\JsonProviderRelationException;
use AV\JsonProvider\Exception\JsonProviderSchemaException;
use AV\JsonProvider\Exception\JsonProviderTableException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\FkBackingPolicyEnum;
use AV\JsonProvider\Schema\IdentifierRules;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\TableLockManager;

/**
 * Index, unique constraint and relation DDL, with the backing indexes
 * probing relations need; index rebuilds.
 *
 * @internal
 */
final class IndexChanges
{
    private readonly IndexManager $indexManager;
    private readonly TableLockManager $locks;
    private readonly MetaRegistry $meta;
    private readonly NdjsonStorage $ndjson;
    private readonly SchemaRegistry $schema;

    public function __construct(
        private readonly Context $context,
        private readonly TableStore $store,
    ) {
        $this->indexManager = $context->indexManager;
        $this->locks = $context->locks;
        $this->meta = $context->meta;
        $this->ndjson = $context->ndjson;
        $this->schema = $context->schema;
    }

    public function rebuildIndex(string $tableName, string $indexName): void
    {
        $this->context->assertWritable();

        $this->locks->withLocks(
            [$tableName => 'ex'],
            'sh',
            function () use ($tableName, $indexName): void {
                $tableSchema = $this->schema->getTable($tableName);

                if ($this->meta->getIndexFormat($tableName) < 2) {
                    $this->rebuildAllStamped($tableSchema);

                    return;
                }

                $indexSchema = $this->indexManager->findIndex(
                    $tableSchema,
                    $indexName,
                );

                $records = $this->store->readAllForWrite($tableName);
                $this->indexManager->rebuildOne(
                    $tableName,
                    $indexSchema,
                    $records,
                );
            },
        );
    }

    public function rebuildAllIndexes(string $tableName): void
    {
        $this->context->assertWritable();

        $this->locks->withLocks(
            [$tableName => 'ex'],
            'sh',
            function () use ($tableName): void {
                $this->rebuildAllStamped($this->schema->getTable($tableName));
            },
        );
    }

    public function addIndex(string $tableName, IndexSchema $index): void
    {
        $this->context->assertWritable();

        $this->locks->withLocks(
            [$tableName => 'ex'],
            'ex',
            function () use ($tableName, $index): void {
                $this->schema->reload();
                $tableSchema = $this->schema->getTable($tableName);

                if (
                    $index->isPrimary
                    || $index->name === IndexSchema::PK_NAME
                ) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::PkIndexNotAddable,
                        $tableName,
                    );
                }

                foreach ($tableSchema->indexes as $existing) {
                    if (
                        IdentifierRules::physicalName($existing->name)
                        === IdentifierRules::physicalName($index->name)
                    ) {
                        throw new JsonProviderSchemaException(
                            JsonProviderErrorEn::IndexAlreadyExists,
                            $tableName,
                            $index->name,
                        );
                    }
                }

                foreach ($index->fields as $field) {
                    if (!isset($tableSchema->columns[$field->field])) {
                        throw new JsonProviderSchemaException(
                            JsonProviderErrorEn::IndexUnknownColumn,
                            $tableName,
                            $index->name,
                            $field->field,
                        );
                    }
                }

                $this->store->ensureTableConsistent($tableSchema);
                $records = $this->store->readAllForWrite($tableName);

                $this->ndjson->createFileFresh(
                    $tableName,
                    $index->getFileName(),
                );

                if ($this->meta->getIndexFormat($tableName) < 2) {
                    $this->indexManager->rebuild($tableSchema, $records);
                }

                $this->indexManager->rebuildOne($tableName, $index, $records);

                if ($this->meta->getIndexFormat($tableName) < 2) {
                    $this->meta->stampIndexFormat($tableName, 2);
                }

                $this->schema->updateTable(
                    $tableName,
                    static fn (TableSchema $t): TableSchema => new TableSchema(
                        name: $t->name,
                        uniqueConstraints: $t->uniqueConstraints,
                        columns: $t->columns,
                        indexes: array_merge($t->indexes, [$index]),
                        tableComment: $t->tableComment,
                        columnComment: $t->columnComment,
                    ),
                );
            },
        );
    }

    public function dropIndex(string $tableName, string $indexName): void
    {
        $this->context->assertWritable();

        $this->locks->withLocks(
            [$tableName => 'ex'],
            'ex',
            function () use ($tableName, $indexName): void {
                $this->schema->reload();
                $tableSchema = $this->schema->getTable($tableName);

                if ($indexName === IndexSchema::PK_NAME) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::PkIndexNotDroppable,
                        $tableName,
                    );
                }

                $found = null;

                foreach ($tableSchema->indexes as $existing) {
                    if ($existing->name === $indexName) {
                        $found = $existing;

                        break;
                    }
                }

                if ($found === null) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::IndexNotFound,
                        $tableName,
                        $indexName,
                    );
                }

                if ($found->isPrimary) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::PkIndexNotDroppable,
                        $tableName,
                    );
                }

                if ($found->isService) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::ReservedIndexName,
                        $indexName,
                    );
                }

                $replacement = $this->backingReplacementFor(
                    $tableName,
                    $found,
                );

                if ($replacement !== null && $replacement->isService) {
                    $this->provisionServiceIndexFile(
                        $tableName,
                        $replacement,
                    );
                }

                $this->schema->mutate(
                    static function (
                        array $tables,
                        array $relations,
                    ) use (
                        $tableName,
                        $indexName,
                        $replacement,
                    ): array {
                        $table = $tables[$tableName]
                            ?? throw new JsonProviderTableException(
                                JsonProviderErrorEn::TableNotFound,
                                $tableName,
                            );
                        $indexes = array_values(array_filter(
                            $table->indexes,
                            static fn (IndexSchema $i): bool => $i
                                ->name !== $indexName,
                        ));

                        if ($replacement !== null) {
                            $present = array_filter(
                                $indexes,
                                static fn (IndexSchema $i): bool => $i
                                    ->name === $replacement->name,
                            );

                            if ($present === []) {
                                $indexes[] = $replacement;
                            }

                            $relations = array_map(
                                static function (
                                    RelationSchema $r,
                                ) use (
                                    $tableName,
                                    $indexName,
                                    $replacement,
                                ): RelationSchema {
                                    $isRepointed = $r
                                        ->childTable() === $tableName
                                        && $r->backingIndex === $indexName;

                                    return $isRepointed
                                        ? $r->withBackingIndex(
                                            $replacement->name,
                                        )
                                        : $r;
                                },
                                $relations,
                            );
                        }

                        $tables[$tableName] = new TableSchema(
                            name: $table->name,
                            uniqueConstraints: $table->uniqueConstraints,
                            columns: $table->columns,
                            indexes: $indexes,
                            tableComment: $table->tableComment,
                            columnComment: $table->columnComment,
                        );

                        return [$tables, $relations];
                    },
                );

                $this->store->invalidateCache($tableName);
                $this->ndjson->deleteFile($tableName, $found->getFileName());
            },
        );
    }

    public function addUniqueConstraint(
        string $tableName,
        UniqueConstraint $constraint,
    ): void {
        $this->context->assertWritable();

        $this->locks->withLocks(
            [$tableName => 'ex'],
            'ex',
            function () use ($tableName, $constraint): void {
                $this->schema->reload();
                $tableSchema = $this->schema->getTable($tableName);

                foreach ($tableSchema->uniqueConstraints as $existing) {
                    if ($existing->name === $constraint->name) {
                        throw new JsonProviderSchemaException(
                            JsonProviderErrorEn::UniqueConstraintAlreadyExists,
                            $tableName,
                            $constraint->name,
                        );
                    }
                }

                foreach ($constraint->fields as $field) {
                    if (!isset($tableSchema->columns[$field])) {
                        throw new JsonProviderSchemaException(
                            JsonProviderErrorEn::UniqueConstraintUnknownColumn,
                            $tableName,
                            $constraint->name,
                            $field,
                        );
                    }
                }

                $this->store->assertNoUniqueDuplicates(
                    $tableName,
                    $constraint,
                    $this->store->readAllForWrite($tableName),
                );

                $this->schema->updateTable(
                    $tableName,
                    static fn (TableSchema $t): TableSchema => new TableSchema(
                        name: $t->name,
                        uniqueConstraints: array_merge(
                            $t->uniqueConstraints,
                            [$constraint],
                        ),
                        columns: $t->columns,
                        indexes: $t->indexes,
                        tableComment: $t->tableComment,
                        columnComment: $t->columnComment,
                    ),
                );
            },
        );
    }

    public function dropUniqueConstraint(
        string $tableName,
        string $name,
    ): void {
        $this->context->assertWritable();

        $this->locks->withLocks(
            [$tableName => 'ex'],
            'ex',
            function () use ($tableName, $name): void {
                $this->schema->reload();
                $tableSchema = $this->schema->getTable($tableName);

                $found = null;

                foreach ($tableSchema->uniqueConstraints as $existing) {
                    if ($existing->name === $name) {
                        $found = $existing;

                        break;
                    }
                }

                if ($found === null) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::UniqueConstraintNotFound,
                        $tableName,
                        $name,
                    );
                }

                $this->assertUniqueNotBackingRelations(
                    $tableSchema,
                    $found,
                );

                $this->schema->updateTable(
                    $tableName,
                    static fn (TableSchema $t): TableSchema => new TableSchema(
                        name: $t->name,
                        uniqueConstraints: array_values(array_filter(
                            $t->uniqueConstraints,
                            static fn (UniqueConstraint $c): bool => $c
                                ->name !== $name,
                        )),
                        columns: $t->columns,
                        indexes: $t->indexes,
                        tableComment: $t->tableComment,
                        columnComment: $t->columnComment,
                    ),
                );
            },
        );
    }

    public function addRelation(RelationSchema $relation): void
    {
        $this->context->assertWritable();

        $child = $relation->childTable();

        $this->locks->withLocks(
            [$child => 'ex'],
            'ex',
            function () use ($relation, $child): void {
                $this->schema->reload();
                SchemaRegistry::assertRelationValid(
                    $this->schema->getTables(),
                    $relation,
                );

                $backing = null;

                if ($relation->needsBackingIndex()) {
                    $backing = $this->resolveBackingIndex(
                        $child,
                        $relation->childColumn(),
                    );

                    if (
                        $backing->isService
                        && !$this->indexDeclared($child, $backing->name)
                    ) {
                        $this->provisionServiceIndexFile($child, $backing);
                        $this->schema->updateTable(
                            $child,
                            static fn (
                                TableSchema $t,
                            ): TableSchema => new TableSchema(
                                name: $t->name,
                                uniqueConstraints: $t->uniqueConstraints,
                                columns: $t->columns,
                                indexes: array_merge(
                                    $t->indexes,
                                    [$backing],
                                ),
                                tableComment: $t->tableComment,
                                columnComment: $t->columnComment,
                            ),
                        );
                    }
                }

                $this->schema->addRelation(
                    $relation->withBackingIndex($backing?->name),
                );
            },
        );
    }

    public function dropRelation(
        string $fromTable,
        string $foreignKey,
        string $toTable,
    ): void {
        $this->context->assertWritable();

        IdentifierRules::assertTableName($fromTable);
        IdentifierRules::assertTableName($toTable);
        IdentifierRules::assertColumnName($foreignKey);

        $childCandidates = array_values(array_unique(
            array_map(
                static fn (RelationSchema $r): string => $r->childTable(),
                array_filter(
                    $this->schema->getAllRelations(),
                    static fn (RelationSchema $r): bool => $r
                        ->fromTable === $fromTable
                        && $r->foreignKey === $foreignKey
                        && $r->toTable === $toTable,
                ),
            ),
        ));

        if ($childCandidates === []) {
            throw new JsonProviderRelationException(
                JsonProviderErrorEn::RelationNotFound,
                $fromTable,
                $foreignKey,
                $toTable,
            );
        }

        $lockPlan = [];

        foreach ($childCandidates as $childTable) {
            $lockPlan[$childTable] = 'ex';
        }

        $this->locks->withLocks(
            $lockPlan,
            'ex',
            function () use ($fromTable, $foreignKey, $toTable): void {
                $this->schema->reload();
                $removed = $this->schema->removeRelation(
                    $fromTable,
                    $foreignKey,
                    $toTable,
                );

                foreach ($removed as $relation) {
                    /*
                     * The lock plan was derived from the pre-lock schema
                     * snapshot; a same-triple relation added concurrently
                     * with a different child table would not be covered.
                     * Its removal is already persisted (correct), but its
                     * backing cleanup must not touch an unlocked table.
                     */
                    if (!$this->locks->isHeld($relation->childTable(), 'ex')) {
                        continue;
                    }

                    $this->releaseServiceBacking($relation);
                }
            },
        );
    }

    /**
     * Drops the SERVICE backing index of a removed relation when no other
     * probing relation on the same child still points to it. User indexes
     * reused as backing are never dropped here.
     */
    public function releaseServiceBacking(RelationSchema $removed): void
    {
        $backing = $removed->backingIndex;

        if (
            $backing === null
            || !str_starts_with(
                $backing,
                IdentifierRules::SERVICE_INDEX_PREFIX,
            )
        ) {
            return;
        }

        $child = $removed->childTable();

        foreach ($this->schema->getAllRelations() as $relation) {
            if (
                $relation->childTable() === $child
                && $relation->backingIndex === $backing
                && $relation->needsBackingIndex()
            ) {
                return;
            }
        }

        $tableSchema = $this->schema->getTable($child);
        $file = null;

        foreach ($tableSchema->indexes as $index) {
            if ($index->name === $backing && $index->isService) {
                $file = $index->getFileName();

                break;
            }
        }

        if ($file === null) {
            return;
        }

        $this->schema->updateTable(
            $child,
            static fn (TableSchema $t): TableSchema => new TableSchema(
                name: $t->name,
                uniqueConstraints: $t->uniqueConstraints,
                columns: $t->columns,
                indexes: array_values(array_filter(
                    $t->indexes,
                    static fn (IndexSchema $i): bool => $i
                        ->name !== $backing,
                )),
                tableComment: $t->tableComment,
                columnComment: $t->columnComment,
            ),
        );
        $this->ndjson->deleteFile($child, $file);
    }

    /**
     * Refuses to drop a single-column unique constraint whose column is
     * the referenced (parent) column of a declared relation, unless
     * another single-column unique constraint on the same column remains.
     * Multi-column constraints never ground a relation (the declaration
     * check accepts only single-column coverage), so they always pass.
     */
    private function assertUniqueNotBackingRelations(
        TableSchema $tableSchema,
        UniqueConstraint $constraint,
    ): void {
        if (\count($constraint->fields) !== 1) {
            return;
        }

        $column = $constraint->fields[0];

        if ($column === PrimaryKey::FIELD) {
            return;
        }

        foreach ($tableSchema->uniqueConstraints as $other) {
            if (
                $other->name !== $constraint->name
                && $other->fields === [$column]
            ) {
                return;
            }
        }

        foreach ($this->schema->getAllRelations() as $relation) {
            if (
                $relation->parentTable() === $tableSchema->name
                && $relation->parentColumn() === $column
            ) {
                throw new JsonProviderRelationException(
                    JsonProviderErrorEn::RelationReferencesNotUnique,
                    $tableSchema->name,
                    $column,
                );
            }
        }
    }

    /**
     * When the index being dropped is the backing of at least one probing
     * relation on this child table, returns the index that must replace
     * it: under LeadingColumn another user index the policy accepts,
     * otherwise the service index descriptor (or an already existing
     * service index on the same column); null when no relation depends on
     * it.
     */
    private function backingReplacementFor(
        string $tableName,
        IndexSchema $dropped,
    ): IndexSchema | null {
        $needed = false;

        foreach ($this->schema->getAllRelations() as $relation) {
            if (
                $relation->childTable() === $tableName
                && $relation->backingIndex === $dropped->name
                && $relation->needsBackingIndex()
            ) {
                $needed = true;

                break;
            }
        }

        if (!$needed) {
            return null;
        }

        $column = $dropped->fields[0]->field;
        $policy = $this->context->fkBackingPolicy;

        if ($policy === FkBackingPolicyEnum::LeadingColumn) {
            $user = $policy->userBacking(
                array_values(array_filter(
                    $this->schema->getTable($tableName)->indexes,
                    static fn (IndexSchema $i): bool => $i
                        ->name !== $dropped->name,
                )),
                $column,
            );

            if ($user !== null) {
                return $user;
            }
        }

        return new IndexSchema(
            name: IdentifierRules::serviceIndexNameFor($column),
            fields: [
                new IndexFieldSchema(
                    $column,
                    SortDirectionEnum::ASC,
                ),
            ],
            isService: true,
        );
    }

    /**
     * Builds the physical file of a service index from the current
     * on-disk records (index machinery identical to addIndex): file
     * first, schema second — a crash in between leaves an orphan
     * *.index.ndjson that repair removes. Requires the table EX lock.
     */
    private function provisionServiceIndexFile(
        string $tableName,
        IndexSchema $index,
    ): void {
        $tableSchema = $this->schema->getTable($tableName);
        $this->store->ensureTableConsistent($tableSchema);
        $records = $this->store->readAllForWrite($tableName);

        $this->ndjson->createFileFresh($tableName, $index->getFileName());

        if ($this->meta->getIndexFormat($tableName) < 2) {
            $this->indexManager->rebuild($tableSchema, $records);
        }

        $this->indexManager->rebuildOne($tableName, $index, $records);

        if ($this->meta->getIndexFormat($tableName) < 2) {
            $this->meta->stampIndexFormat($tableName, 2);
        }
    }

    /**
     * Picks the backing index for a probing relation on the child column:
     * a USER index the backing policy accepts is reused
     * (FkBackingPolicyEnum::userBacking); otherwise the service descriptor
     * "_fk_<column>" is returned (which may itself already be declared by
     * another relation on the same column and is then shared).
     */
    private function resolveBackingIndex(
        string $childTable,
        string $column,
    ): IndexSchema {
        $user = $this->context->fkBackingPolicy->userBacking(
            $this->schema->getTable($childTable)->indexes,
            $column,
        );

        if ($user !== null) {
            return $user;
        }

        return new IndexSchema(
            name: IdentifierRules::serviceIndexNameFor($column),
            fields: [
                new IndexFieldSchema(
                    $column,
                    SortDirectionEnum::ASC,
                ),
            ],
            isService: true,
        );
    }

    private function indexDeclared(string $tableName, string $name): bool
    {
        foreach ($this->schema->getTable($tableName)->indexes as $index) {
            if ($index->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rebuilds every index of the table with the current encoder and
     * stamps indexFormat 2. A single-index rebuild on a pre-v2 table
     * escalates here: rebuilding one file in the new format while the
     * rest stay v1 would poison the per-table format marker. Requires
     * the table EX lock.
     */
    private function rebuildAllStamped(TableSchema $tableSchema): void
    {
        $records = $this->store->readAllForWrite($tableSchema->name);

        $this->indexManager->rebuild($tableSchema, $records);

        if ($this->meta->getIndexFormat($tableSchema->name) < 2) {
            $this->meta->stampIndexFormat($tableSchema->name, 2);
        }
    }
}
