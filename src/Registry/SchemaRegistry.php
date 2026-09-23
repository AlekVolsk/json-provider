<?php

declare(strict_types=1);

namespace AV\JsonProvider\Registry;

use AV\JsonProvider\Exception\JsonProviderRelationException;
use AV\JsonProvider\Exception\JsonProviderSchemaException;
use AV\JsonProvider\Exception\JsonProviderTableException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IdentifierRules;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\JsonStorageTxHandle;
use AV\JsonProvider\Validation\ColumnTypeInfo;

/**
 * Schema registry — parses information_schema.json and exposes table and
 * relation metadata.
 *
 * Reads are cached in memory and revalidated against the file's stat
 * (mtime+size) on every access, so external or cross-process schema changes
 * are picked up without an explicit reload.
 *
 * Every mutation goes through mutate(): a read-modify-write transaction
 * under the schema file's sidecar lock that parses the FRESH on-disk state,
 * applies the change, and persists — a stale in-memory copy can never
 * overwrite another writer's tables or relations.
 *
 * Physical I/O is delegated to JsonStorage — the registry has no knowledge
 * of the DB location.
 *
 * @phpstan-type SchemaTables array<string,TableSchema>
 * @phpstan-type SchemaRelations array<int,RelationSchema>
 * @phpstan-type SchemaState array{SchemaTables, SchemaRelations}
 */
final class SchemaRegistry
{
    private const string SCHEMA_FILE = 'information_schema.json';

    /** @var null|array<string,TableSchema> */
    private array | null $tables = null;

    /** @var null|array<int,RelationSchema> */
    private array | null $relations = null;

    private int | null $loadedMtime = null;

    private int | null $loadedSize = null;

    private int | null $loadedIno = null;

    public function __construct(
        private readonly JsonStorage $storage,
    ) {
    }

    /**
     * Returns the table schema by name; throws if it does not exist.
     */
    public function getTable(string $name): TableSchema
    {
        $this->ensureLoaded();

        return $this->tables[$name]
            ?? throw new JsonProviderTableException(
                JsonProviderErrorEn::TableNotFound,
                $name,
            );
    }

    /**
     * Returns whether the table is registered in the schema.
     */
    public function hasTable(string $name): bool
    {
        $this->ensureLoaded();

        return isset($this->tables[$name]);
    }

    /**
     * Returns all registered tables.
     *
     * @return array<string,TableSchema>
     */
    public function getTables(): array
    {
        $this->ensureLoaded();

        return $this->tables ?? [];
    }

    /**
     * Returns all relations that involve the given table.
     *
     * @return array<int,RelationSchema>
     */
    public function getRelations(string $tableName): array
    {
        $this->ensureLoaded();

        return array_values(array_filter(
            $this->relations ?? [],
            static fn (RelationSchema $r): bool => $r->fromTable === $tableName
                || $r->toTable === $tableName,
        ));
    }

    /**
     * Returns every declared relation.
     *
     * @return array<int,RelationSchema>
     */
    public function getAllRelations(): array
    {
        $this->ensureLoaded();

        return array_values($this->relations ?? []);
    }

    /**
     * Returns relations where the given table is the CANONICAL parent —
     * the side whose rows are referenced by the FK. For belongsTo edges
     * that is the toTable, for hasMany/hasOne the fromTable; filtering by
     * the raw toTable would mis-wire hasMany edges (their child is the
     * toTable) and fire cascades into the parent.
     *
     * @return array<int,RelationSchema>
     */
    public function getChildRelations(string $tableName): array
    {
        $this->ensureLoaded();

        return array_values(array_filter(
            $this->relations ?? [],
            static fn (RelationSchema $r): bool => $r
                ->parentTable() === $tableName,
        ));
    }

    /**
     * Declares a new relation: validates it against the fresh on-disk
     * state, rejects a duplicate of an already declared edge by its
     * CANONICAL identity (one edge declared as belongsTo and again as the
     * mirrored hasMany must not create two enforcing copies), then
     * persists. Runs inside mutate(), so a concurrent DDL cannot slip
     * between the checks and the write.
     */
    public function addRelation(RelationSchema $relation): void
    {
        $this->mutate(
            static function (
                array $tables,
                array $relations,
            ) use ($relation): array {
                self::assertRelationValid($tables, $relation);

                foreach ($relations as $existing) {
                    if (
                        $existing->canonicalKey() === $relation->canonicalKey()
                    ) {
                        throw new JsonProviderRelationException(
                            JsonProviderErrorEn::RelationAlreadyExists,
                            $relation->fromTable,
                            $relation->foreignKey,
                            $relation->toTable,
                            self::declarationOf($existing),
                        );
                    }
                }

                $relations[] = $relation;

                return [$tables, $relations];
            },
        );
    }

    /**
     * Removes every relation matching the raw (fromTable, foreignKey,
     * toTable) triple and returns the removed descriptors (the caller
     * decides the fate of their backing indexes). Nothing matched —
     * RELATION_NOT_FOUND.
     *
     * @return array<int,RelationSchema>
     */
    public function removeRelation(
        string $fromTable,
        string $foreignKey,
        string $toTable,
    ): array {
        $removed = [];

        $this->mutate(
            static function (
                array $tables,
                array $relations,
            ) use (
                $fromTable,
                $foreignKey,
                $toTable,
                &$removed,
            ): array {
                $kept = [];

                foreach ($relations as $relation) {
                    $matches = $relation->fromTable === $fromTable
                        && $relation->foreignKey === $foreignKey
                        && $relation->toTable === $toTable;

                    if ($matches) {
                        $removed[] = $relation;
                    } else {
                        $kept[] = $relation;
                    }
                }

                if ($removed === []) {
                    throw new JsonProviderRelationException(
                        JsonProviderErrorEn::RelationNotFound,
                        $fromTable,
                        $foreignKey,
                        $toTable,
                    );
                }

                return [$tables, $kept];
            },
        );

        return $removed;
    }

    /**
     * Rewrites every relation through the given transform in one schema
     * RMW — the hook for keeping RelationSchema::backingIndex in sync when
     * index DDL touches a backing index.
     *
     * @param callable(RelationSchema): RelationSchema $transform
     */
    public function mapRelations(callable $transform): void
    {
        $this->mutate(
            static function (
                array $tables,
                array $relations,
            ) use ($transform): array {
                return [$tables, array_map($transform, $relations)];
            },
        );
    }

    /**
     * Semantic validation of a relation declaration against a table set,
     * by the canonical sides:
     *
     *  1. both tables exist — TABLE_NOT_FOUND;
     *  2. the FK column exists in the child table and the referenced
     *     column in the parent table — RELATION_COLUMN_NOT_FOUND with a
     *     hint on which side holds the FK for the declared type;
     *  3. the base column types match, the "|null" suffix is ignored —
     *     RELATION_TYPE_MISMATCH;
     *  4. the referenced column is the PK or is covered by a
     *     single-column unique constraint — RELATION_REFERENCES_NOT_UNIQUE
     *     (a non-unique parent column would make one FK value address an
     *     unpredictable row set);
     *  5. onUpdate on the PK is undeclarable — the engine never rewrites
     *     id, so the action could not ever fire — RELATION_ON_UPDATE_ON_PK;
     *  6. a SET_NULL action requires a nullable FK column —
     *     FOREIGN_KEY_SET_NULL_NOT_NULLABLE.
     *
     * Used by the relation DDL; legacy relations loaded from disk are NOT
     * re-validated here (load is structurally strict only) — the FK
     * engine re-checks the edges it is about to execute.
     *
     * @param array<string,TableSchema> $tables
     */
    public static function assertRelationValid(
        array $tables,
        RelationSchema $relation,
    ): void {
        $childTable = $relation->childTable();
        $parentTable = $relation->parentTable();

        foreach ([$childTable, $parentTable] as $tableName) {
            if (!isset($tables[$tableName])) {
                throw new JsonProviderTableException(
                    JsonProviderErrorEn::TableNotFound,
                    $tableName,
                );
            }
        }

        $childColumns = $tables[$childTable]->columns;
        $parentColumns = $tables[$parentTable]->columns;

        foreach (
            [
                [$relation->childColumn(), $childTable, $childColumns],
                [$relation->parentColumn(), $parentTable, $parentColumns],
            ] as [$column, $holder, $columns]
        ) {
            if (!\array_key_exists($column, $columns)) {
                throw new JsonProviderRelationException(
                    JsonProviderErrorEn::RelationColumnNotFound,
                    $relation->fromTable,
                    $relation->foreignKey,
                    $relation->toTable,
                    $relation->references,
                    $column,
                    $holder,
                );
            }
        }

        $childInfo = ColumnTypeInfo::parse(
            $childColumns[$relation->childColumn()],
        );
        $parentInfo = ColumnTypeInfo::parse(
            $parentColumns[$relation->parentColumn()],
        );

        if ($childInfo->base !== $parentInfo->base) {
            throw new JsonProviderRelationException(
                JsonProviderErrorEn::RelationTypeMismatch,
                $childTable,
                $relation->childColumn(),
                $childColumns[$relation->childColumn()],
                $parentTable,
                $relation->parentColumn(),
                $parentColumns[$relation->parentColumn()],
            );
        }

        if (
            $relation->parentColumn() !== PrimaryKey::FIELD
            && !self::coveredBySingleColumnUnique(
                $tables[$parentTable],
                $relation->parentColumn(),
            )
        ) {
            throw new JsonProviderRelationException(
                JsonProviderErrorEn::RelationReferencesNotUnique,
                $parentTable,
                $relation->parentColumn(),
            );
        }

        if (
            $relation->parentColumn() === PrimaryKey::FIELD
            && $relation->onUpdate !== ForeignKeyActionEnum::NO_ACTION
        ) {
            throw new JsonProviderRelationException(
                JsonProviderErrorEn::RelationOnUpdateOnPk,
                $relation->fromTable,
                $relation->toTable,
            );
        }

        $setNull = ForeignKeyActionEnum::SET_NULL;

        if (
            ($relation->onDelete === $setNull
                || $relation->onUpdate === $setNull)
            && !$childInfo->nullable
        ) {
            throw new JsonProviderRelationException(
                JsonProviderErrorEn::ForeignKeySetNullNotNullable,
                $childTable,
                $relation->childColumn(),
            );
        }
    }

    /**
     * Read-modify-write mutation of the schema file under its sidecar lock.
     *
     * The callback receives tables and relations parsed from the FRESH
     * on-disk contents (never the in-memory cache) and returns the new
     * [tables, relations] pair to persist, or null to abort without
     * writing. All existence/duplicate checks belong inside the callback so
     * they see the current state even when another process changed the
     * schema since this registry last read it.
     *
     * @param callable(SchemaTables, SchemaRelations): (null|SchemaState) $apply
     */
    public function mutate(callable $apply): void
    {
        $this->storage->transaction(
            self::SCHEMA_FILE,
            function (array $data, JsonStorageTxHandle $h) use ($apply): void {
                $tables = $this->parseTables($data);
                $relations = $this->parseRelations($data);

                $result = $apply($tables, $relations);

                if ($result === null) {
                    return;
                }

                $h->save($this->serialize($result[0], $result[1]));
            },
        );

        $this->tables = null;
        $this->relations = null;
        $this->loadedMtime = null;
        $this->loadedSize = null;
        $this->loadedIno = null;
    }

    /**
     * Registers a new table in the schema and persists information_schema.json.
     * The duplicate check runs against the fresh on-disk state: a lost race
     * with a concurrent createTable surfaces as TABLE_ALREADY_EXISTS. Names
     * differing only in letter case are duplicates — they share one
     * directory (IdentifierRules::physicalName).
     */
    public function registerTable(TableSchema $table): void
    {
        $this->mutate(
            static function (
                array $tables,
                array $relations,
            ) use ($table): array {
                $physical = IdentifierRules::physicalName($table->name);

                foreach (array_keys($tables) as $existing) {
                    if (
                        IdentifierRules::physicalName($existing) === $physical
                    ) {
                        throw new JsonProviderTableException(
                            JsonProviderErrorEn::TableAlreadyExists,
                            $table->name,
                        );
                    }
                }

                $tables[$table->name] = $table;

                return [$tables, $relations];
            },
        );
    }

    /**
     * Replaces an existing table descriptor with a new one and persists the
     * schema. Throws if no table with that name exists.
     *
     * Used by JsonDataProvider::reorderColumns and similar atomic schema
     * mutations.
     */
    public function replaceTable(TableSchema $table): void
    {
        $this->mutate(
            static function (
                array $tables,
                array $relations,
            ) use ($table): array {
                if (!isset($tables[$table->name])) {
                    throw new JsonProviderTableException(
                        JsonProviderErrorEn::TableNotFound,
                        $table->name,
                    );
                }

                $tables[$table->name] = $table;

                return [$tables, $relations];
            },
        );
    }

    /**
     * Applies $transform to the fresh on-disk descriptor of the table and
     * persists the result. $transform receives the current TableSchema and
     * returns the modified one; column-level validation belongs inside the
     * transform. Returns the persisted descriptor.
     *
     * @param callable(TableSchema): TableSchema $transform
     */
    public function updateTable(string $name, callable $transform): TableSchema
    {
        $updated = null;

        $this->mutate(
            static function (
                array $tables,
                array $relations,
            ) use (
                $name,
                $transform,
                &$updated,
            ): array {
                $current = $tables[$name]
                    ?? throw new JsonProviderTableException(
                        JsonProviderErrorEn::TableNotFound,
                        $name,
                    );

                $updated = $transform($current);
                $tables[$name] = $updated;

                return [$tables, $relations];
            },
        );

        if (!$updated instanceof TableSchema) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::SchemaTransformNoResult,
                $name,
            );
        }

        return $updated;
    }

    /**
     * Removes a table from the schema together with every relation that
     * involves it, and persists information_schema.json. Idempotent — an
     * unknown table is a no-op (nothing is written).
     */
    public function unregisterTable(string $name): void
    {
        $this->mutate(
            static function (
                array $tables,
                array $relations,
            ) use ($name): array | null {
                if (!isset($tables[$name])) {
                    return null;
                }

                unset($tables[$name]);

                $relations = array_values(array_filter(
                    $relations,
                    static fn (RelationSchema $r): bool => $r
                        ->fromTable !== $name
                        && $r->toTable !== $name,
                ));

                return [$tables, $relations];
            },
        );
    }

    /**
     * Parses and validates a raw information_schema snapshot (an already
     * json-decoded array) WITHOUT touching the on-disk schema. The exact
     * strict loaders of the live schema are reused — table and column
     * names go through IdentifierRules (path-traversal protection),
     * column types through the closed type list, relation entries and
     * actions through the strict relation parser — so an invalid snapshot
     * throws before anything is applied.
     *
     * Used by the restore service to vet an archived schema before
     * adopting it.
     *
     * @param array<mixed> $raw
     *
     * @return SchemaState
     */
    public function parseSnapshot(array $raw): array
    {
        return [$this->parseTables($raw), $this->parseRelations($raw)];
    }

    /**
     * Replaces the ENTIRE schema (tables and relations) with the given
     * state and persists information_schema.json. The caller owns the
     * consistency of the state (restore adopts a snapshot validated by
     * parseSnapshot) and must hold the database EX lock.
     *
     * @param SchemaTables    $tables
     * @param SchemaRelations $relations
     */
    public function replaceAll(array $tables, array $relations): void
    {
        $this->mutate(
            static fn (
                array $currentTables,
                array $currentRelations,
            ): array => [$tables, $relations],
        );
    }

    /**
     * Forces a schema reload from disk (after external modifications).
     */
    public function reload(): void
    {
        $this->tables = null;
        $this->relations = null;
        $this->loadedMtime = null;
        $this->loadedSize = null;
        $this->loadedIno = null;
        $this->ensureLoaded();
    }

    /**
     * The signature of a declared relation, used to point at the declaration
     * that already occupies the edge.
     */
    private static function declarationOf(RelationSchema $relation): string
    {
        return $relation->type->value . ' ' . $relation->fromTable
            . '(' . $relation->foreignKey . ') -> '
            . $relation->toTable . '(' . $relation->references . ')';
    }

    private static function coveredBySingleColumnUnique(
        TableSchema $table,
        string $column,
    ): bool {
        foreach ($table->uniqueConstraints as $constraint) {
            if ($constraint->fields === [$column]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Loads the schema on first access and revalidates the cached copy
     * against the file's stat on every subsequent one: an mtime or size
     * change (another process, an external edit) triggers a re-read. The
     * stat is taken BEFORE the read, so a write landing in between only
     * causes one extra re-read next time — never a stale cache.
     */
    private function ensureLoaded(): void
    {
        $stat = $this->storage->stat(self::SCHEMA_FILE);

        if (
            $this->tables !== null
            && $this->loadedMtime === $stat['mtime']
            && $this->loadedSize === $stat['size']
            && $this->loadedIno === $stat['ino']
        ) {
            return;
        }

        $data = $this->storage->read(self::SCHEMA_FILE);

        $this->tables = $this->parseTables($data);
        $this->relations = $this->parseRelations($data);
        $this->loadedMtime = $stat['mtime'];
        $this->loadedSize = $stat['size'];
        $this->loadedIno = $stat['ino'];
    }

    /**
     * Strict parse of the 'tables' section. Every structural deviation —
     * a missing or non-array 'tables' key, a non-object table definition,
     * a non-string column type — raises INVALID_SCHEMA with the exact
     * address of the problem instead of silently dropping the entry: a
     * silently skipped table would read as "table does not exist" and
     * could cascade into data loss. An empty 'tables' collection stays
     * valid. Name and type validity are enforced by the TableSchema
     * constructor.
     *
     * @param array<mixed> $data
     *
     * @return array<string,TableSchema>
     */
    private function parseTables(array $data): array
    {
        if (!\array_key_exists('tables', $data)) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::SchemaTablesMissing,
            );
        }

        if (!\is_array($data['tables'])) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::SchemaTablesNotCollection,
            );
        }

        $tables = [];

        foreach ($data['tables'] as $name => $def) {
            $name = (string)$name;

            if (!\is_array($def)) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::SchemaTableNotObject,
                    $name,
                );
            }

            $columns = $def['columns'] ?? [];

            if (!\is_array($columns)) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::SchemaColumnsNotObject,
                    $name,
                );
            }

            /** @var array<string,string> $typedColumns */
            $typedColumns = [];

            foreach ($columns as $colName => $colType) {
                /*
                 * A purely numeric column key decodes as int and would
                 * crash identifier validation with a TypeError further
                 * down; reject it here at the json boundary.
                 */
                if (\is_int($colName)) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::InvalidColumnName,
                        (string)$colName,
                    );
                }

                if (!\is_string($colType)) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::SchemaColumnTypeNotString,
                        $name,
                        $colName,
                    );
                }

                $typedColumns[$colName] = $colType;
            }

            $rawTableComment = $def['tableComment'] ?? null;
            $tableComment = \is_string($rawTableComment)
                && $rawTableComment !== '' ? $rawTableComment : null;

            $tables[$name] = new TableSchema(
                name: $name,
                uniqueConstraints: $this->parseUniqueConstraints(
                    $name,
                    $def['unique'] ?? [],
                ),
                columns: $typedColumns,
                indexes: $this->parseIndexes($name, $def['indexes'] ?? []),
                tableComment: $tableComment,
                columnComment: $this->parseColumnComment(
                    $def['columnComment'] ?? [],
                    $typedColumns,
                ),
            );
        }

        $physical = [];

        foreach (array_keys($tables) as $name) {
            $key = IdentifierRules::physicalName($name);

            if (isset($physical[$key])) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::SchemaTableNamesClash,
                    $physical[$key],
                    $name,
                );
            }

            $physical[$key] = $name;
        }

        return $tables;
    }

    /**
     * Strict parse of a table's 'unique' section: a broken name or fields
     * list raises INVALID_SCHEMA instead of silently dropping the
     * constraint — a dropped constraint would stop being enforced on the
     * next insert without anyone noticing.
     *
     * @return array<int,UniqueConstraint>
     */
    private function parseUniqueConstraints(string $table, mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::SchemaUniqueNotList,
                $table,
            );
        }

        $constraints = [];

        foreach ($raw as $def) {
            if (!\is_array($def)) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::SchemaUniqueNotObject,
                    $table,
                );
            }

            $constraintName = $def['name'] ?? null;

            if (!\is_string($constraintName) || $constraintName === '') {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::SchemaUniqueName,
                    $table,
                );
            }

            $fields = $def['fields'] ?? null;

            if (!\is_array($fields) || $fields === []) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::SchemaUniqueFields,
                    $table,
                    $constraintName,
                );
            }

            $typedFields = [];

            foreach ($fields as $field) {
                if (!\is_string($field)) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::SchemaUniqueFieldNotString,
                        $table,
                        $constraintName,
                    );
                }

                $typedFields[] = $field;
            }

            $constraints[] = new UniqueConstraint(
                $constraintName,
                $typedFields,
            );
        }

        return $constraints;
    }

    /**
     * Parses the column-comment map. Keeps only string comments for columns
     * that actually exist in the table, so a stale hand-edited entry does not
     * survive.
     *
     * @param array<string,string> $columns
     *
     * @return array<string,string>
     */
    private function parseColumnComment(mixed $raw, array $columns): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $comments = [];

        foreach ($raw as $field => $text) {
            if (
                \is_string($field)
                && \is_string($text)
                && $text !== ''
                && isset($columns[$field])
            ) {
                $comments[$field] = $text;
            }
        }

        return $comments;
    }

    /**
     * Strict parse of a table's 'indexes' section: a broken entry raises
     * INVALID_SCHEMA instead of silently dropping the index — a dropped
     * index entry would orphan its file and silently change query plans.
     * A missing direction defaults to 'asc'; a present one must be a valid
     * lowercase SortDirectionEnum value.
     *
     * @return array<int,IndexSchema>
     */
    private function parseIndexes(string $table, mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::SchemaIndexesNotList,
                $table,
            );
        }

        $indexes = [];

        foreach ($raw as $def) {
            if (!\is_array($def)) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::SchemaIndexNotObject,
                    $table,
                );
            }

            $indexName = $def['name'] ?? null;

            if (!\is_string($indexName) || $indexName === '') {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::SchemaIndexName,
                    $table,
                );
            }

            $rawFields = $def['fields'] ?? null;

            if (!\is_array($rawFields) || $rawFields === []) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::SchemaIndexFields,
                    $table,
                    $indexName,
                );
            }

            $fields = [];

            foreach ($rawFields as $fieldDef) {
                if (!\is_array($fieldDef)) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::SchemaIndexFieldNotObject,
                        $table,
                        $indexName,
                    );
                }

                $fieldName = $fieldDef['field'] ?? null;

                if (!\is_string($fieldName) || $fieldName === '') {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::SchemaIndexFieldName,
                        $table,
                        $indexName,
                    );
                }

                $direction = SortDirectionEnum::ASC;

                if (\array_key_exists('direction', $fieldDef)) {
                    $rawDir = $fieldDef['direction'];
                    $parsedDir = \is_string($rawDir)
                        ? SortDirectionEnum::tryFrom($rawDir)
                        : null;

                    if ($parsedDir === null) {
                        throw new JsonProviderSchemaException(
                            JsonProviderErrorEn::SchemaIndexDirection,
                            $table,
                            $indexName,
                            $fieldName,
                            var_export($rawDir, true),
                        );
                    }

                    $direction = $parsedDir;
                }

                $fields[] = new IndexFieldSchema($fieldName, $direction);
            }

            $isPrimary = isset($def['isPrimary']) && $def['isPrimary'] === true;
            $isService = isset($def['isService']) && $def['isService'] === true;

            $indexes[] = new IndexSchema(
                $indexName,
                $fields,
                $isPrimary,
                $isService,
            );
        }

        return $indexes;
    }

    /**
     * Strict parse of the 'relations' section. A silently skipped entry
     * would turn an enforced FK into nothing (no cascade, no restrict), so
     * every structural deviation is loud:
     *
     *  - an entry that is not an object, or one whose from / foreignKey /
     *    to / references keys are missing or non-string (catches typos
     *    like 'form') — RELATION_ENTRY_INVALID listing expected vs actual
     *    keys;
     *  - an unknown 'type' — RELATION_ENTRY_INVALID listing the allowed
     *    values;
     *  - a present onDelete/onUpdate that is not a valid
     *    ForeignKeyActionEnum value ('CASCADE', 'set_null') —
     *    RELATION_ACTION_INVALID; an absent key stays the legal NO_ACTION
     *    default.
     *
     * Semantic validation (tables/columns exist, types match) is not done
     * here — that belongs to the relation API and the FK engine.
     *
     * @param array<mixed> $data
     *
     * @return array<int,RelationSchema>
     */
    private function parseRelations(array $data): array
    {
        $relations = [];

        if (!\array_key_exists('relations', $data)) {
            return $relations;
        }

        if (!\is_array($data['relations'])) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::SchemaRelationsNotList,
            );
        }

        foreach ($data['relations'] as $position => $def) {
            if (!\is_array($def)) {
                throw new JsonProviderRelationException(
                    JsonProviderErrorEn::RelationEntryNotObject,
                    (string)$position,
                );
            }

            foreach (['from', 'foreignKey', 'to', 'references'] as $key) {
                if (!isset($def[$key]) || !\is_string($def[$key])) {
                    $val = implode(', ', array_map(
                        static fn (int | string $k): string => (string)$k,
                        array_keys($def),
                    ));
                    throw new JsonProviderRelationException(
                        JsonProviderErrorEn::RelationEntryKey,
                        (string)$position,
                        $key,
                        $val,
                    );
                }
            }

            $rawType = $def['type'] ?? null;
            $type = \is_string($rawType)
                ? RelationTypeEnum::tryFrom($rawType)
                : null;

            if ($type === null) {
                throw new JsonProviderRelationException(
                    JsonProviderErrorEn::RelationEntryType,
                    (string)$position,
                    var_export($rawType, true),
                );
            }

            $backingIndex = null;

            if (\array_key_exists('backingIndex', $def)) {
                if (!\is_string($def['backingIndex'])) {
                    throw new JsonProviderRelationException(
                        JsonProviderErrorEn::RelationEntryBackingIndex,
                        (string)$position,
                    );
                }

                $backingIndex = $def['backingIndex'];
            }

            $relations[] = new RelationSchema(
                fromTable: $def['from'],
                foreignKey: $def['foreignKey'],
                toTable: $def['to'],
                references: $def['references'],
                type: $type,
                onDelete: $this->parseRelationAction($def, 'onDelete'),
                onUpdate: $this->parseRelationAction($def, 'onUpdate'),
                backingIndex: $backingIndex,
            );
        }

        return $relations;
    }

    /**
     * An absent action key is the legal NO_ACTION default; a present one
     * must parse exactly (case-sensitive camelCase per the enum values) —
     * 'CASCADE' or 'set_null' silently becoming NO_ACTION would disable an
     * FK the author believed was enforced.
     *
     * @param array<mixed> $def
     */
    private function parseRelationAction(
        array $def,
        string $key,
    ): ForeignKeyActionEnum {
        if (!\array_key_exists($key, $def)) {
            return ForeignKeyActionEnum::NO_ACTION;
        }

        $raw = $def[$key];
        $action = \is_string($raw)
            ? ForeignKeyActionEnum::tryFrom($raw)
            : null;

        if ($action === null) {
            throw new JsonProviderRelationException(
                JsonProviderErrorEn::RelationActionInvalid,
                $key,
                \is_scalar($raw) ? (string)$raw : \gettype($raw),
            );
        }

        return $action;
    }

    /**
     * Serializes the given schema state into the information_schema.json
     * shape. Pure: no I/O, no reads of the in-memory cache.
     *
     * @param array<string,TableSchema> $tables
     * @param array<int,RelationSchema> $relations
     *
     * @return array<string,mixed>
     */
    private function serialize(array $tables, array $relations): array
    {
        $data = ['tables' => [], 'relations' => []];

        foreach ($tables as $name => $table) {
            $unique = [];

            foreach ($table->uniqueConstraints as $constraint) {
                $unique[] = [
                    'name'   => $constraint->name,
                    'fields' => $constraint->fields,
                ];
            }

            $indexes = [];

            foreach ($table->indexes as $index) {
                $indexFields = [];

                foreach ($index->fields as $f) {
                    $indexFields[] = [
                        'field'     => $f->field,
                        'direction' => $f->direction->value,
                    ];
                }

                $indexEntry = [
                    'name'   => $index->name,
                    'fields' => $indexFields,
                ];

                if ($index->isPrimary) {
                    $indexEntry['isPrimary'] = true;
                }

                if ($index->isService) {
                    $indexEntry['isService'] = true;
                }

                $indexes[] = $indexEntry;
            }

            $tableData = [];

            if ($table->tableComment !== null) {
                $tableData['tableComment'] = $table->tableComment;
            }

            $tableData['columns'] = $table->columns;

            if ($table->columnComment !== []) {
                $tableData['columnComment'] = $table->columnComment;
            }

            $tableData['unique'] = $unique;
            $tableData['indexes'] = $indexes;

            $data['tables'][$name] = $tableData;
        }

        foreach ($relations as $relation) {
            $entry = [
                'from'       => $relation->fromTable,
                'foreignKey' => $relation->foreignKey,
                'to'         => $relation->toTable,
                'references' => $relation->references,
                'type'       => $relation->type->value,
            ];

            if ($relation->onDelete !== ForeignKeyActionEnum::NO_ACTION) {
                $entry['onDelete'] = $relation->onDelete->value;
            }

            if ($relation->onUpdate !== ForeignKeyActionEnum::NO_ACTION) {
                $entry['onUpdate'] = $relation->onUpdate->value;
            }

            if ($relation->backingIndex !== null) {
                $entry['backingIndex'] = $relation->backingIndex;
            }

            $data['relations'][] = $entry;
        }

        if ($data['tables'] === []) {
            $data['tables'] = new \stdClass();
        }

        return $data;
    }
}
