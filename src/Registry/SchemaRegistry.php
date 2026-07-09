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

/**
 * Schema registry — parses information_schema.json and exposes table and
 * relation metadata. Loaded lazily on first access and cached in memory.
 *
 * Physical I/O is delegated to JsonStorage — the registry has no knowledge
 * of the DB location.
 */
final class SchemaRegistry
{
    private const string SCHEMA_FILE = 'information_schema.json';

    /** @var null|array<string,TableSchema> */
    private array | null $tables = null;

    /** @var null|array<int,RelationSchema> */
    private array | null $relations = null;

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
     * Registers a new table in the schema and persists information_schema.json.
     */
    public function registerTable(TableSchema $table): void
    {
        $this->ensureLoaded();

        if (isset($this->tables[$table->name])) {
            throw StorageException::tableAlreadyExists($table->name);
        }

        $this->tables[$table->name] = $table;
        $this->persist();
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
        $this->ensureLoaded();

        if (!isset($this->tables[$table->name])) {
            throw StorageException::tableNotFound($table->name);
        }

        $this->tables[$table->name] = $table;
        $this->persist();
    }

    /**
     * Removes a table from the schema together with every relation that
     * involves it, and persists information_schema.json. Idempotent — an
     * unknown table is a no-op.
     */
    public function unregisterTable(string $name): void
    {
        $this->ensureLoaded();

        if (!isset($this->tables[$name])) {
            return;
        }

        unset($this->tables[$name]);

        $this->relations = array_values(array_filter(
            $this->relations ?? [],
            static fn (RelationSchema $r): bool => $r->fromTable !== $name
                && $r->toTable !== $name,
        ));

        $this->persist();
    }

    /**
     * Forces a schema reload from disk (after external modifications).
     */
    public function reload(): void
    {
        $this->tables = null;
        $this->relations = null;
        $this->ensureLoaded();
    }

    private function ensureLoaded(): void
    {
        if ($this->tables !== null) {
            return;
        }

        $data = $this->storage->read(self::SCHEMA_FILE);

        $this->tables = $this->parseTables($data);
        $this->relations = $this->parseRelations($data);
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
            if (!\is_string($name) || !\is_array($def)) {
                continue;
            }

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
     * Serializes the current schema back to information_schema.json.
     */
    private function persist(): void
    {
        $data = ['tables' => [], 'relations' => []];

        foreach ($this->tables ?? [] as $name => $table) {
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

        foreach ($this->relations ?? [] as $relation) {
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

        $this->storage->write(self::SCHEMA_FILE, $data);
    }
}
