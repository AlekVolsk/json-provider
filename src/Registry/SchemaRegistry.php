<?php

declare(strict_types=1);

namespace AV\JsonProvider\Registry;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\JsonStorageTxHandle;

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
    ) {}

    /**
     * Returns the table schema by name; throws if it does not exist.
     */
    public function getTable(string $name): TableSchema
    {
        $this->ensureLoaded();

        return $this->tables[$name]
            ?? throw StorageException::tableNotFound($name);
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
     * Returns relations where the given table is the parent (toTable).
     *
     * @return array<int,RelationSchema>
     */
    public function getChildRelations(string $tableName): array
    {
        $this->ensureLoaded();

        return array_values(array_filter(
            $this->relations ?? [],
            static fn (RelationSchema $r): bool => $r->toTable === $tableName,
        ));
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
     * with a concurrent createTable surfaces as TABLE_ALREADY_EXISTS.
     */
    public function registerTable(TableSchema $table): void
    {
        $this->mutate(
            static function (
                array $tables,
                array $relations,
            ) use ($table): array {
                if (isset($tables[$table->name])) {
                    throw StorageException::tableAlreadyExists($table->name);
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
                    throw StorageException::tableNotFound($table->name);
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
                    ?? throw StorageException::tableNotFound($name);

                $updated = $transform($current);
                $tables[$name] = $updated;

                return [$tables, $relations];
            },
        );

        \assert($updated instanceof TableSchema);

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
     * @param array<mixed> $data
     *
     * @return array<string,TableSchema>
     */
    private function parseTables(array $data): array
    {
        $tables = [];

        if (!isset($data['tables']) || !\is_array($data['tables'])) {
            return $tables;
        }

        foreach ($data['tables'] as $name => $def) {
            if (!\is_array($def)) {
                continue;
            }

            $name = (string)$name;

            $columns = isset($def['columns'])
                && \is_array($def['columns']) ? $def['columns'] : [];

            /** @var array<string,string> $typedColumns */
            $typedColumns = [];

            foreach ($columns as $colName => $colType) {
                if (\is_string($colName) && \is_string($colType)) {
                    $typedColumns[$colName] = $colType;
                }
            }

            $rawTableComment = $def['tableComment'] ?? null;
            $tableComment = \is_string($rawTableComment)
                && $rawTableComment !== '' ? $rawTableComment : null;

            $tables[$name] = new TableSchema(
                name: $name,
                uniqueConstraints: $this->parseUniqueConstraints(
                    $def['unique'] ?? [],
                ),
                columns: $typedColumns,
                indexes: $this->parseIndexes($def['indexes'] ?? []),
                tableComment: $tableComment,
                columnComment: $this->parseColumnComment(
                    $def['columnComment'] ?? [],
                    $typedColumns,
                ),
            );
        }

        return $tables;
    }

    /**
     * @return array<int,UniqueConstraint>
     */
    private function parseUniqueConstraints(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $constraints = [];

        foreach ($raw as $def) {
            if (!\is_array($def)) {
                continue;
            }

            $constraintName = isset($def['name'])
                && \is_string($def['name']) ? $def['name'] : '';
            $fields = isset($def['fields'])
                && \is_array($def['fields']) ? $def['fields'] : [];

            /** @var array<int,string> $typedFields */
            $typedFields = array_values(array_filter($fields, 'is_string'));

            if ($constraintName === '' || $typedFields === []) {
                continue;
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
     * @return array<int,IndexSchema>
     */
    private function parseIndexes(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $indexes = [];

        foreach ($raw as $def) {
            if (!\is_array($def)) {
                continue;
            }

            $indexName = isset($def['name'])
                && \is_string($def['name']) ? $def['name'] : '';

            if ($indexName === '') {
                continue;
            }

            $fields = [];
            $rawFields = $def['fields'] ?? [];

            foreach (\is_array($rawFields) ? $rawFields : [] as $fieldDef) {
                if (!\is_array($fieldDef)) {
                    continue;
                }

                $fieldName = isset($fieldDef['field'])
                    && \is_string($fieldDef['field'])
                    ? $fieldDef['field']
                    : '';
                $rawDir = isset($fieldDef['direction'])
                    && \is_string($fieldDef['direction'])
                    ? $fieldDef['direction']
                    : 'asc';
                $direction = SortDirectionEnum::tryFrom($rawDir)
                    ?? SortDirectionEnum::ASC;

                if ($fieldName === '') {
                    continue;
                }

                $fields[] = new IndexFieldSchema($fieldName, $direction);
            }

            if ($fields === []) {
                continue;
            }

            $isPrimary = isset($def['isPrimary']) && $def['isPrimary'] === true;

            $indexes[] = new IndexSchema($indexName, $fields, $isPrimary);
        }

        return $indexes;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<int,RelationSchema>
     */
    private function parseRelations(array $data): array
    {
        $relations = [];

        if (!isset($data['relations']) || !\is_array($data['relations'])) {
            return $relations;
        }

        foreach ($data['relations'] as $def) {
            if (!\is_array($def)) {
                continue;
            }

            $rawType = $def['type'] ?? '';
            $type = RelationTypeEnum::tryFrom(
                \is_scalar($rawType) ? (string)$rawType : '',
            );

            if (
                $type === null
                || !isset(
                    $def['from'],
                    $def['foreignKey'],
                    $def['to'],
                    $def['references']
                )
                || !\is_string($def['from'])
                || !\is_string($def['foreignKey'])
                || !\is_string($def['to'])
                || !\is_string($def['references'])
            ) {
                continue;
            }

            $rawOnDelete = $def['onDelete'] ?? '';
            $rawOnUpdate = $def['onUpdate'] ?? '';

            $relations[] = new RelationSchema(
                fromTable: $def['from'],
                foreignKey: $def['foreignKey'],
                toTable: $def['to'],
                references: $def['references'],
                type: $type,
                onDelete: ForeignKeyActionEnum::tryFrom(
                    \is_scalar($rawOnDelete) ? (string)$rawOnDelete : '',
                )
                    ?? ForeignKeyActionEnum::NO_ACTION,
                onUpdate: ForeignKeyActionEnum::tryFrom(
                    \is_scalar($rawOnUpdate) ? (string)$rawOnUpdate : '',
                )
                    ?? ForeignKeyActionEnum::NO_ACTION,
            );
        }

        return $relations;
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

            $data['relations'][] = $entry;
        }

        if ($data['tables'] === []) {
            $data['tables'] = new \stdClass();
        }

        return $data;
    }
}
