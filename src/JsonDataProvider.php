<?php

declare(strict_types=1);

namespace AV\JsonProvider;

use AV\JsonProvider\Cache\CacheInterface;
use AV\JsonProvider\Cache\NullCache;
use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Exception\LocaleInterface;
use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Mapping\DtoMap;
use AV\JsonProvider\Mapping\DtoMapper;
use AV\JsonProvider\Mapping\DtoRegistry;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Services\Backup\Backup;
use AV\JsonProvider\Services\Backup\Restore;
use AV\JsonProvider\Services\Integrity\IntegrityRepairer;
use AV\JsonProvider\Services\Integrity\IntegrityReport;
use AV\JsonProvider\Services\Integrity\IntegrityValidator;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Validation\ValueValidator;

/**
 * Core data provider engine.
 * Handles: cache, auto-increment id, unique constraint checks, CRUD over
 * NDJSON table files.
 * Knows nothing about domain entities — works with raw
 * array<string,scalar|null>.
 *
 * Singleton: one instance per storage path.
 * Get an instance: JsonDataProvider::getInstance(string $path).
 * Create a new storage: JsonDataProvider::createDatabase(string $path).
 */
final class JsonDataProvider
{
    /** @var array<string,self> */
    private static array $instances = [];

    private readonly NdjsonStorage $ndjson;
    private readonly JsonStorage $json;
    private readonly SchemaRegistry $schema;
    private readonly CacheInterface $cache;
    private readonly IndexManager $indexManager;
    private readonly MetaRegistry $meta;
    private readonly ValueValidator $values;
    private readonly DtoRegistry $dtoRegistry;
    private readonly DtoMapper $dtoMapper;
    private IntegrityValidator | null $validator = null;
    private IntegrityRepairer | null $repairer = null;
    private Backup | null $backup = null;
    private Restore | null $restore = null;
    private readonly string $dbPath;

    private function __construct(
        string $dbPath,
        CacheInterface | null $cache = null,
    ) {
        $this->dbPath = $dbPath;
        $this->ndjson = new NdjsonStorage($dbPath);
        $this->json = new JsonStorage($dbPath);
        $this->schema = new SchemaRegistry($this->json);
        $this->cache = $cache ?? new NullCache();
        $this->indexManager = new IndexManager($this->ndjson);
        $this->meta = new MetaRegistry($this->json);
        $this->values = new ValueValidator();
        $this->dtoRegistry = new DtoRegistry();
        $this->dtoMapper = new DtoMapper();
    }

    /**
     * Returns the singleton instance for the given storage path.
     * The storage must already exist (contain information_schema.json).
     */
    public static function getInstance(
        string $dbPath,
        CacheInterface | null $cache = null,
    ): self {
        if (!isset(self::$instances[$dbPath])) {
            self::$instances[$dbPath] = new self($dbPath, $cache);
        }

        return self::$instances[$dbPath];
    }

    /**
     * Returns whether a storage exists at the given path.
     * A storage is considered to exist if the directory contains
     * information_schema.json.
     */
    public static function exists(string $dbPath): bool
    {
        return (new JsonStorage($dbPath))->exists('information_schema.json');
    }

    /**
     * Creates a new storage at the given path and returns its singleton.
     * Throws if the storage already exists.
     */
    public static function createDatabase(
        string $dbPath,
        CacheInterface | null $cache = null,
    ): self {
        $bootstrap = JsonStorage::createRoot($dbPath);
        $bootstrap->createFile(
            'information_schema.json',
            ['tables' => new \stdClass(), 'relations' => []],
        );
        $bootstrap->createObjectFile('meta.json');

        self::$instances[$dbPath] = new self($dbPath, $cache);

        return self::$instances[$dbPath];
    }

    /**
     * Sets the locale for all provider exception messages.
     * Takes effect globally for all instances in the current process.
     */
    public function setLocale(LocaleInterface $locale): self
    {
        JsonProviderException::setLocale($locale);

        return $this;
    }

    /**
     * Binds one or more DTO classes to their tables (read from the
     * #[JsonProviderRecord] attribute). Each class is compiled and validated
     * against the table schema now, so a DTO/schema mismatch fails here rather
     * than on the first query. Call after the tables exist.
     *
     * @param class-string ...$classes
     */
    public function registerDto(string ...$classes): self
    {
        foreach ($classes as $class) {
            $table = DtoMap::tableName($class);
            $schema = $this->schema->getTable($table);
            $this->dtoRegistry->register(DtoMap::compile($class, $schema));
        }

        return $this;
    }

    /**
     * Entry point for building a query against a table.
     */
    public function table(string $tableName): JsonTable
    {
        return new JsonTable(
            $this,
            $this->schema->getTable($tableName),
            $this->dtoRegistry->forTable($tableName),
            $this->dtoMapper,
        );
    }

    /**
     * Creates a new table: registers it in the schema, creates the NDJSON file
     * and empty files for every declared index (so append on insert does
     * not fail).
     */
    public function createTable(TableSchema $tableSchema): void
    {
        if ($this->schema->hasTable($tableSchema->name)) {
            throw StorageException::tableAlreadyExists($tableSchema->name);
        }

        $this->ndjson->createFile(
            $tableSchema->name,
            $tableSchema->getFileName(),
        );

        foreach ($tableSchema->indexes as $index) {
            $this->ndjson->createFile(
                $tableSchema->name,
                $index->getFileName(),
            );
        }

        $this->schema->registerTable($tableSchema);
        $this->meta->initTable($tableSchema->name);
    }

    /**
     * Drops a table entirely: removes its schema descriptor (with every
     * relation that involves it), its meta entry, its files and any bound DTO
     * mapping. A no-op when the table is unknown (idempotent).
     *
     * The schema entry is removed first, so the invariant "every registered
     * table has a meta entry" is never broken mid-operation: should physical
     * file removal fail, the table is already logically gone rather than left
     * registered without meta.
     *
     * Foreign keys are not enforced here: dropping a parent table silently
     * removes its relations and leaves any child FK columns/values dangling
     * (cf. SQL DROP TABLE, not DROP TABLE ... RESTRICT). Drop or migrate the
     * children first if that matters.
     */
    public function dropTable(string $tableName): void
    {
        if (!$this->schema->hasTable($tableName)) {
            return;
        }

        $this->schema->unregisterTable($tableName);
        $this->meta->dropEntry($tableName);
        $this->ndjson->deleteTable($tableName);
        $this->dtoRegistry->unregister($tableName);
        $this->invalidateCache($tableName);
    }

    /**
     * Whether a table is registered in the schema.
     */
    public function hasTable(string $tableName): bool
    {
        return $this->schema->hasTable($tableName);
    }

    /**
     * Names of all tables registered in the schema.
     *
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_keys($this->schema->getTables());
    }

    /**
     * Column names of a table in schema order (the primary key comes first).
     * Throws if the table does not exist.
     *
     * @return list<string>
     */
    public function columnNames(string $tableName): array
    {
        return array_keys($this->schema->getTable($tableName)->columns);
    }

    /**
     * Aligns an existing table's columns to the given schema (a mini ALTER):
     *  - adds columns present in $desired but not stored — existing rows get a
     *    type-appropriate default ('' / 0 / 0.0 / false, or null for a nullable
     *    type), so typed reads keep working;
     *  - drops columns present in the table but not in $desired — their values
     *    are removed from every row;
     *  - fixes column order to match $desired.
     * The indexes and unique constraints carried by $desired become the table's
     * new definitions: index files are created for indexes new to the table and
     * removed for those no longer present, then every index is rebuilt.
     * Rewrites the data file and updates meta. Returns the added/dropped column
     * names; a no-op (empty lists) when columns and order already match — in
     * that case indexes/constraints/comments are left untouched.
     *
     * Guards (all throw StorageException, nothing is written):
     *  - the table does not exist;
     *  - a retained column changes type — this method never re-encodes data, so
     *    a type change must be migrated separately;
     *  - a unique constraint or index in $desired references a column absent
     *    from $desired->columns;
     *  - a non-nullable column with no zero-value default (temporal, year,
     *    month, day) is added to a non-empty table — declare it nullable.
     *
     * @return array{added: list<string>, dropped: list<string>}
     */
    public function migrateColumns(TableSchema $desired): array
    {
        $current = $this->schema->getTable($desired->name);

        $this->assertNoColumnTypeChange($current, $desired);
        $this->assertSchemaFieldsDeclared($desired);

        $currentColumns = array_keys($current->columns);
        $desiredColumns = array_keys($desired->columns);

        if ($currentColumns === $desiredColumns) {
            return ['added' => [], 'dropped' => []];
        }

        $added = array_values(array_diff($desiredColumns, $currentColumns));
        $dropped = array_values(array_diff($currentColumns, $desiredColumns));

        $records = $this->readAllRaw($desired->name);

        if ($records !== []) {
            $this->assertAddedColumnsHaveDefault($desired, $added);
        }

        $migrated = [];

        foreach ($records as $record) {
            $row = [];

            foreach ($desired->columns as $column => $type) {
                $row[$column] = \array_key_exists($column, $record)
                    ? $record[$column]
                    : self::defaultForType($type);
            }

            $migrated[] = $row;
        }

        $this->createMissingIndexFiles($desired);
        $this->schema->replaceTable($desired);
        $this->writeAll($desired->name, $desired, $migrated);
        $this->deleteOrphanIndexFiles($current, $desired);

        return ['added' => $added, 'dropped' => $dropped];
    }

    /**
     * Reads all records from a table (with cache).
     *
     * @return array<int,array<string,null|scalar>>
     */
    public function readAll(string $tableName): array
    {
        $tableSchema = $this->schema->getTable($tableName);
        $records = $this->readAllRaw($tableName);
        $decoded = [];

        foreach ($records as $record) {
            $decoded[] = $this->values->decodeRecord($tableSchema, $record);
        }

        return $decoded;
    }

    /**
     * Inserts a new record; id is assigned automatically (max + 1, minimum 1).
     * Returns the assigned id.
     *
     * @param array<string,null|scalar> $record
     */
    public function insert(string $tableName, array $record): int
    {
        $tableSchema = $this->schema->getTable($tableName);
        $record = $this->values->encodeForWrite($tableSchema, $record, true);

        if ($tableSchema->uniqueConstraints !== []) {
            $records = $this->readAllRaw($tableName);
            $this->checkUniqueConstraints(
                $tableSchema,
                $records,
                $record,
                null,
            );
        }

        // Кодируемость проверяем ДО выделения id/строки в мете: иначе
        // сбой json_encode
        // (битый UTF-8, INF/NAN) оставит дыру в нумерации строк и собьёт
        // индексы записей.
        $probe = $this->normalizeRecord($tableSchema, $record + ['id' => 0]);

        if (json_encode($probe) === false) {
            throw StorageException::invalidRecord(
                $tableSchema->name,
                json_last_error_msg(),
            );
        }

        $alloc = $this->meta->allocateInsert($tableName);
        $record['id'] = $alloc['id'];
        $lineNumber = $alloc['line'];
        $record = $this->normalizeRecord($tableSchema, $record);

        $this->ndjson->append(
            $tableSchema->name,
            $tableSchema->getFileName(),
            $record,
        );
        $this->appendIndexes($tableSchema, $record, $lineNumber);
        $this->invalidateCache($tableName);

        return $alloc['id'];
    }

    /**
     * Updates all records matching the given conditions with the same data
     * patch.
     * Returns the number of updated records (0 if no matches — empty match set
     * is not a failure). The 'id' key in $data is silently ignored.
     *
     * @param array<int,FilterCondition> $conditions
     * @param array<string,null|scalar>  $data
     */
    public function update(
        string $tableName,
        array $conditions,
        array $data,
    ): int {
        $tableSchema = $this->schema->getTable($tableName);
        $conditions = $this->values->encodeConditions(
            $tableSchema,
            $conditions,
        );
        $records = $this->readAllRaw($tableName);

        unset($data['id']);
        $data = $this->values->encodeForWrite($tableSchema, $data, false);

        $targetIndexes = [];

        foreach ($records as $index => $existing) {
            if ($this->matchesAll($existing, $conditions)) {
                $targetIndexes[] = $index;
            }
        }

        if ($targetIndexes === []) {
            return 0;
        }

        foreach ($targetIndexes as $index) {
            $oldRecord = $records[$index];
            $updated = array_merge($oldRecord, $data);
            $excludeId = isset($oldRecord['id'])
                && \is_int($oldRecord['id'])
                ? $oldRecord['id']
                : null;

            $this->checkUniqueConstraints(
                $tableSchema,
                $records,
                $updated,
                $excludeId,
            );
            $this->processForeignKeysOnUpdate($tableName, $oldRecord, $updated);

            $records[$index] = $updated;
        }

        $this->writeAll($tableName, $tableSchema, $records);

        return \count($targetIndexes);
    }

    /**
     * Deletes records matching all conditions. Returns the number of deleted
     * records (0 if no matches — empty set is not a failure).
     *
     * @param array<int,FilterCondition> $conditions
     */
    public function delete(string $tableName, array $conditions): int
    {
        $tableSchema = $this->schema->getTable($tableName);
        $conditions = $this->values->encodeConditions(
            $tableSchema,
            $conditions,
        );
        $records = $this->readAllRaw($tableName);

        $toDelete = array_filter(
            $records,
            fn (array $r): bool => $this->matchesAll($r, $conditions),
        );

        if ($toDelete === []) {
            return 0;
        }

        $this->processForeignKeys($tableName, $toDelete, 'delete');

        $filtered = array_values(array_filter(
            $records,
            fn (array $r): bool => !$this->matchesAll($r, $conditions),
        ));

        $this->writeAll($tableName, $tableSchema, $filtered);

        return \count($toDelete);
    }

    /**
     * Returns the most recently allocated auto-increment id for the table.
     * 0 on an empty table (no inserts ever). Not rolled back on delete:
     * ids are never reused (cf. SQL AUTO_INCREMENT).
     * O(1) — reads meta.json.
     */
    public function getLastInsertedId(string $tableName): int
    {
        return $this->meta->getLastInsertedId($tableName);
    }

    /**
     * Returns the id that WILL be allocated to the next insert
     * (lastInsertedId + 1).
     * Does not reserve the id for the caller — a parallel insert may consume
     * the value. Use as a prediction (path names, identifiers for external
     * systems before the actual insert).
     * O(1) — reads meta.json.
     */
    public function getNextId(string $tableName): int
    {
        return $this->meta->getNextId($tableName);
    }

    /**
     * Reorders columns of an existing table.
     *
     * $newOrder is a list of column names in the desired order. Behaviour:
     *  - unknown columns — StorageException::reorderColumnsUnknown;
     *  - duplicate columns — StorageException::reorderColumnsDuplicate;
     *  - id missing in $newOrder — id is prepended;
     *  - id present but not first — id is moved to position 0;
     *  - after the id-normalization the list must contain every existing
     *    column; otherwise StorageException::reorderColumnsIncomplete.
     *
     * Order of disk operations: schema first, then NDJSON data. Rationale:
     * if the data write fails after the schema write, lazy normalization on
     * the next mutation will re-emit each record in the new column order.
     * If the schema write fails first, the operation is invisible.
     *
     * Indexes are not rebuilt — column order in records does not affect
     * index file contents (key/line pairs only). For a forced rebuild use
     * rebuildIndex / rebuildAllIndexes.
     *
     * @param array<int,string> $newOrder
     */
    public function reorderColumns(string $tableName, array $newOrder): void
    {
        $tableSchema = $this->schema->getTable($tableName);

        $this->validateNewOrder($tableSchema, $newOrder);

        $normalized = $this->normalizeNewOrder($newOrder);

        if (\count($normalized) !== \count($tableSchema->columns)) {
            $missing = array_diff(
                array_keys($tableSchema->columns),
                $normalized,
            );

            throw StorageException::reorderColumnsIncomplete(
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

        $this->schema->replaceTable($newSchema);

        $records = $this->readAllRaw($tableName);
        $this->writeAll($tableName, $newSchema, $records);
    }

    /**
     * Sets (or clears, when null) the human description of a table.
     *
     * Schema-only meta-operation: rewrites information_schema.json but never
     * reads or rewrites table data, indexes, meta, or the data cache. Comments
     * live "beside" the structure and are preserved across createTable and
     * reorderColumns.
     */
    public function setTableComment(
        string $tableName,
        string | null $comment,
    ): void {
        $tableSchema = $this->schema->getTable($tableName);
        $this->schema->replaceTable($tableSchema->withTableComment($comment));
    }

    /**
     * Returns the table comment, or null if it has none.
     */
    public function getTableComment(string $tableName): string | null
    {
        return $this->schema->getTable($tableName)->tableComment;
    }

    /**
     * Sets (or clears, when null/empty) the description of a single column.
     * Throws StorageException::columnNotFound if the column is not declared in
     * the table. Schema-only meta-operation — data and indexes are untouched.
     */
    public function setColumnComment(
        string $tableName,
        string $column,
        string | null $comment,
    ): void {
        $tableSchema = $this->schema->getTable($tableName);

        if (!isset($tableSchema->columns[$column])) {
            throw StorageException::columnNotFound($tableName, $column);
        }

        $this->schema->replaceTable(
            $tableSchema->withColumnComment($column, $comment),
        );
    }

    /**
     * Sets the column-comment map. By default replaces the whole map; with
     * $merge=true merges the given entries on top of the existing ones. Every
     * key must be an existing column, otherwise
     * StorageException::columnNotFound.
     * An empty-string value clears that column. Schema-only meta-operation.
     *
     * @param array<string,string> $comments column name => description
     */
    public function setColumnComments(
        string $tableName,
        array $comments,
        bool $merge = false,
    ): void {
        $tableSchema = $this->schema->getTable($tableName);

        foreach (array_keys($comments) as $column) {
            if (!isset($tableSchema->columns[$column])) {
                throw StorageException::columnNotFound($tableName, $column);
            }
        }

        $effective = $merge
            ? array_merge($tableSchema->columnComment, $comments)
            : $comments;

        $this->schema->replaceTable(
            $tableSchema->withColumnComments($effective),
        );
    }

    /**
     * Returns the comment for a single column, or null if it has none (or the
     * column does not exist).
     */
    public function getColumnComment(
        string $tableName,
        string $column,
    ): string | null {
        return $this->schema->getTable($tableName)->getColumnComment($column);
    }

    /**
     * Returns the column-comment map (column name => description) for the
     * table.
     * Only documented columns are present.
     *
     * @return array<string,string>
     */
    public function getColumnComments(string $tableName): array
    {
        return $this->schema->getTable($tableName)->columnComment;
    }

    /**
     * Returns a documentation-oriented view of the table: its comment plus
     * every column with its declared type and description (null when
     * undocumented).
     * Column order follows the schema.
     *
     * @return array{
     *     name: string,
     *     comment: null|string,
     *     columns: array<int,array{
     *         name: string,
     *         type: string,
     *         comment: null|string
     *     }>
     * }
     */
    public function describeTable(string $tableName): array
    {
        $tableSchema = $this->schema->getTable($tableName);
        $columns = [];

        foreach ($tableSchema->columns as $name => $type) {
            $columns[] = [
                'name'    => $name,
                'type'    => $type,
                'comment' => $tableSchema->getColumnComment($name),
            ];
        }

        return [
            'name'    => $tableSchema->name,
            'comment' => $tableSchema->tableComment,
            'columns' => $columns,
        ];
    }

    /**
     * Rebuilds a single named index for the table from current data.
     * Equivalent to "delete the index file and recreate it" but with no
     * window when the index file is missing — NdjsonStorage::write replaces
     * the file under flock.
     *
     * Throws StorageException::indexNotFound if the index name does not
     * exist in the table schema.
     */
    public function rebuildIndex(string $tableName, string $indexName): void
    {
        $tableSchema = $this->schema->getTable($tableName);
        $indexSchema = $this->indexManager->findIndex($tableSchema, $indexName);

        $records = $this->readAllRaw($tableName);
        $this->indexManager->rebuildOne($tableName, $indexSchema, $records);
    }

    /**
     * Rebuilds every index of the table from current data, including PK.
     * Sequential per index; each rebuild atomically replaces its own file.
     */
    public function rebuildAllIndexes(string $tableName): void
    {
        $tableSchema = $this->schema->getTable($tableName);
        $records = $this->readAllRaw($tableName);

        $this->indexManager->rebuild($tableSchema, $records);
    }

    /**
     * Heavy-weight preventive optimization for a single table:
     * sorts records by id ASC and rebuilds every index. Cache is invalidated.
     *
     * Use when you suspect record drift from natural insert order
     * (e.g. after many deletes) or simply as a periodic maintenance task.
     */
    public function optimizeTable(string $tableName): void
    {
        $this->repairer()->optimizeTable($tableName);
        $this->invalidateCache($tableName);
    }

    /**
     * Validates a single table against the storage contract; returns a report.
     * Read-only — never mutates anything.
     */
    public function validateTable(string $tableName): IntegrityReport
    {
        return $this->validator()->validateTable($tableName);
    }

    /**
     * Validates the entire database; returns a report.
     * Read-only — never mutates anything.
     */
    public function validate(): IntegrityReport
    {
        return $this->validator()->validateDatabase();
    }

    /**
     * Repairs a single table where possible; returns the report listing
     * all findings together with their repair status.
     * Cache for the table is invalidated.
     */
    public function repairTable(string $tableName): IntegrityReport
    {
        $report = $this->repairer()->repairTable($tableName);
        $this->invalidateCache($tableName);

        return $report;
    }

    /**
     * Repairs the entire database where possible; returns the full report.
     * All per-table caches are invalidated.
     */
    public function repair(): IntegrityReport
    {
        $report = $this->repairer()->repairDatabase();

        foreach (array_keys($this->schema->getTables()) as $tableName) {
            $this->invalidateCache($tableName);
        }

        return $report;
    }

    /**
     * Exports the current DB state to a .tar.gz archive at $destination.
     * Returns the absolute path of the created archive.
     *
     * If $destination is a directory, the file name is generated as
     * "backup-YYYY-MM-DD_HHMMSS.tar.gz" inside it. The destination must lie
     * outside the DB directory.
     */
    public function backup(string $destination): string
    {
        return $this->backupService()->export($destination);
    }

    /**
     * Restores DB state from a .tar.gz archive previously produced by backup().
     * The archive's table set must exactly match the current schema. On any
     * failure mid-restore, the original DB state is rolled back from a safety
     * snapshot taken automatically before the restore begins.
     *
     * Caches for all tables are invalidated.
     */
    public function restore(string $archivePath): void
    {
        $this->restoreService()->restore($archivePath);

        foreach (array_keys($this->schema->getTables()) as $tableName) {
            $this->invalidateCache($tableName);
        }
    }

    /**
     * Selects records by conditions, ordering, and pagination.
     * Uses an ordering-index if available, then a filter-index, otherwise
     * falls back to full scan.
     *
     * @param array<int,FilterCondition> $conditions
     * @param array<int,OrderBy>         $ordering
     * @param array<int,string>          $distinctFields
     *
     * @return array<int,array<string,null|scalar>>
     */
    public function select(
        string $tableName,
        array $conditions = [],
        array $ordering = [],
        int | null $limit = null,
        int $offset = 0,
        array $distinctFields = [],
    ): array {
        $tableSchema = $this->schema->getTable($tableName);
        $conditions = $this->values->encodeConditions(
            $tableSchema,
            $conditions,
        );
        $index = $this->resolveIndex($tableSchema, $ordering, $conditions);
        $paginatedByIndex = false;

        if (
            $index !== null
            && $ordering !== []
            && $index->matchesOrdering($ordering)
        ) {
            $records = $this->selectViaIndex(
                $index,
                $tableSchema,
                $conditions,
                $ordering,
                $limit,
                $offset,
            );
            $paginatedByIndex = true;
        } elseif ($index !== null) {
            $records = $this->selectViaIndex(
                $index,
                $tableSchema,
                $conditions,
                $ordering,
            );
        } else {
            $records = $this->readAllRaw($tableName);

            if ($conditions !== []) {
                $records = array_values(array_filter(
                    $records,
                    fn (array $r): bool => $this->matchesAll($r, $conditions),
                ));
            }

            if ($ordering !== []) {
                $this->sortByOrdering($records, $ordering);
            }
        }

        if ($distinctFields !== []) {
            $seen = [];
            $deduped = [];

            foreach ($records as $record) {
                $key = implode("\x00", array_map(
                    static fn (string $f): string => (string)(
                        $record[$f] ?? ''
                    ),
                    $distinctFields,
                ));

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $deduped[] = $record;
                }
            }

            $records = $deduped;
            $paginatedByIndex = false;
        }

        if (!$paginatedByIndex && ($offset > 0 || $limit !== null)) {
            $records = \array_slice($records, $offset, $limit);
        }

        $decoded = [];

        foreach ($records as $record) {
            $decoded[] = $this->values->decodeRecord($tableSchema, $record);
        }

        return $decoded;
    }

    /**
     * Counts records matching the given conditions.
     *
     * @param array<int,FilterCondition> $conditions
     */
    public function count(string $tableName, array $conditions = []): int
    {
        $tableSchema = $this->schema->getTable($tableName);
        $records = $this->readAllRaw($tableName);

        if ($conditions === []) {
            return \count($records);
        }

        $conditions = $this->values->encodeConditions(
            $tableSchema,
            $conditions,
        );

        return \count(array_filter(
            $records,
            fn (array $r): bool => $this->matchesAll($r, $conditions),
        ));
    }

    /**
     * Invalidates the table cache (call after external writes that bypass
     * the provider).
     */
    public function invalidateCache(string $tableName): void
    {
        $this->cache->invalidate($this->cacheKey($tableName));
    }

    /**
     * Default value for a freshly added column, by its declared type. Nullable
     * types default to null; the four base scalar types to their zero value.
     * Types with no meaningful zero (temporal, year/month/day) are rejected
     * upstream by assertAddedColumnsHaveDefault before this is reached on a
     * non-empty table, so the null fallback here is never persisted as a
     * not-null value.
     */
    private static function defaultForType(
        string $type
    ): bool | float | int | string | null {
        if (str_ends_with($type, '|null')) {
            return null;
        }

        return match ($type) {
            ColumnTypes::STRING => '',
            ColumnTypes::INT    => 0,
            ColumnTypes::FLOAT  => 0.0,
            ColumnTypes::BOOL   => false,
            default             => null,
        };
    }

    /**
     * Whether a freshly added not-null column of this type has a usable
     * zero-value default. Only the four base scalar types (and any nullable
     * type, which defaults to null) qualify; temporal and year/month/day types
     * have no sensible zero and must be declared nullable when added to a
     * non-empty table.
     */
    private static function hasSafeDefault(string $type): bool
    {
        if (str_ends_with($type, '|null')) {
            return true;
        }

        return match ($type) {
            ColumnTypes::STRING,
            ColumnTypes::INT,
            ColumnTypes::FLOAT,
            ColumnTypes::BOOL => true,
            default           => false,
        };
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
                throw StorageException::migrateColumnTypeChange(
                    $desired->name,
                    $column,
                    $currentType,
                    $type,
                );
            }
        }
    }

    /**
     * Ensures every unique-constraint field and index field in $desired refers
     * to a column that $desired actually declares — otherwise the constraint or
     * index would compute keys over a missing field (empty string for all
     * rows), yielding phantom collisions and broken lookups.
     */
    private function assertSchemaFieldsDeclared(TableSchema $desired): void
    {
        foreach ($desired->uniqueConstraints as $constraint) {
            foreach ($constraint->fields as $field) {
                if (!isset($desired->columns[$field])) {
                    throw StorageException::migrateFieldUnknownColumn(
                        $desired->name,
                        'unique constraint "' . $constraint->name . '"',
                        $field,
                    );
                }
            }
        }

        foreach ($desired->indexes as $index) {
            foreach ($index->fields as $field) {
                if (!isset($desired->columns[$field->field])) {
                    throw StorageException::migrateFieldUnknownColumn(
                        $desired->name,
                        'index "' . $index->name . '"',
                        $field->field,
                    );
                }
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

            if (!self::hasSafeDefault($type)) {
                throw StorageException::migrateColumnNoDefault(
                    $desired->name,
                    $column,
                    $type,
                );
            }
        }
    }

    /**
     * Creates an empty index file for every index in $desired that has none on
     * disk yet, so the subsequent rebuild (which replaces existing files under
     * flock) does not fail on a brand-new index.
     */
    private function createMissingIndexFiles(TableSchema $desired): void
    {
        foreach ($desired->indexes as $index) {
            if (!$this->ndjson->exists($desired->name, $index->getFileName())) {
                $this->ndjson->createFile(
                    $desired->name,
                    $index->getFileName(),
                );
            }
        }
    }

    /**
     * Removes index files whose index is present in the current schema but no
     * longer in $desired (e.g. the index's column was dropped), so no orphan
     * index file is left behind in the table directory.
     */
    private function deleteOrphanIndexFiles(
        TableSchema $current,
        TableSchema $desired,
    ): void {
        $keep = [];

        foreach ($desired->indexes as $index) {
            $keep[$index->name] = true;
        }

        foreach ($current->indexes as $index) {
            if (!isset($keep[$index->name])) {
                $this->ndjson->deleteFile(
                    $desired->name,
                    $index->getFileName(),
                );
            }
        }
    }

    /**
     * Reads all records in their stored (canonical UTC) form, with cache.
     *
     * This is the internal read path: it feeds index rebuilds, uniqueness
     * checks, foreign keys and count — all of which must see the exact stored
     * values, never the timezone-localized presentation. Public reads go
     * through readAll()/select(), which decode temporal columns at the very
     * end.
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function readAllRaw(string $tableName): array
    {
        $cacheKey = $this->cacheKey($tableName);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $records = $this->ndjson->read(
            $tableName,
            $this->schema->getTable($tableName)->getFileName(),
        );
        $this->cache->set($cacheKey, $records);

        return $records;
    }

    /**
     * Сортирует записи на месте по правилам ordering (стабильно — usort
     * в PHP 8+).
     *
     * @param array<int,array<string,null|scalar>> $records
     * @param array<int,OrderBy>                   $ordering
     */
    private function sortByOrdering(array &$records, array $ordering): void
    {
        usort($records, function (array $a, array $b) use ($ordering): int {
            foreach ($ordering as $order) {
                $cmp = $this->compareValues(
                    $a[$order->field] ?? null,
                    $b[$order->field] ?? null,
                );

                if ($cmp !== 0) {
                    return $order->direction === SortDirectionEnum::ASC
                        ? $cmp
                        : -$cmp;
                }
            }

            return 0;
        });
    }

    /**
     * @param array<int,array<string,null|scalar>> $records
     */
    private function writeAll(
        string $tableName,
        TableSchema $tableSchema,
        array $records,
    ): void {
        $records = array_values(array_map(
            fn (array $r): array => $this->normalizeRecord($tableSchema, $r),
            $records,
        ));
        $this->ndjson->write(
            $tableSchema->name,
            $tableSchema->getFileName(),
            $records,
        );
        $this->indexManager->rebuild($tableSchema, $records);
        $this->meta->setLineCount($tableName, \count($records));
        $this->cache->set($this->cacheKey($tableName), $records);
    }

    /**
     * Selects records using an index.
     * Ordering-index: reads lines in index order, then applies conditions.
     * Filter-index: reads only the lines found via index search, then
     * applies all conditions.
     *
     * @param array<int,FilterCondition> $conditions
     * @param array<int,OrderBy>         $ordering
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function selectViaIndex(
        IndexSchema $index,
        TableSchema $tableSchema,
        array $conditions,
        array $ordering,
        int | null $limit = null,
        int $offset = 0,
    ): array {
        if ($ordering !== [] && $index->matchesOrdering($ordering)) {
            $entries = $this->indexManager->readIndex(
                $tableSchema->name,
                $index,
            );
            $lineNumbers = array_column($entries, 'line');

            if ($conditions === []) {
                if ($offset > 0 || $limit !== null) {
                    $lineNumbers = \array_slice($lineNumbers, $offset, $limit);
                }

                return $this->ndjson->readLines(
                    $tableSchema->name,
                    $tableSchema->getFileName(),
                    $lineNumbers,
                );
            }

            return $this->readFilteredPaginated(
                $tableSchema->name,
                $tableSchema->getFileName(),
                $lineNumbers,
                $conditions,
                $offset,
                $limit,
            );
        }

        $lineNumbers = null;

        foreach ($conditions as $condition) {
            $lines = $this->indexManager->searchLines(
                $tableSchema->name,
                $index,
                $condition,
            );

            if ($lines !== null) {
                $lineNumbers = $lines;
                break;
            }
        }

        $records = $lineNumbers !== null
            ? $this->ndjson->readLines(
                $tableSchema->name,
                $tableSchema->getFileName(),
                $lineNumbers,
            )
            : $this->ndjson->read(
                $tableSchema->name,
                $tableSchema->getFileName(),
            );

        if ($conditions !== []) {
            $records = array_values(array_filter(
                $records,
                fn (array $r): bool => $this->matchesAll($r, $conditions),
            ));
        }

        // Индекс покрыл только условия, но не сортировку — досортировываем
        // в памяти.
        if ($ordering !== []) {
            $this->sortByOrdering($records, $ordering);
        }

        return $records;
    }

    /**
     * Reads records by lineNumbers, filters, applies offset/limit without
     * loading all into memory.
     *
     * @param array<int,int>             $lineNumbers
     * @param array<int,FilterCondition> $conditions
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function readFilteredPaginated(
        string $tableName,
        string $file,
        array $lineNumbers,
        array $conditions,
        int $offset,
        int | null $limit,
    ): array {
        $records = $this->ndjson->readLines($tableName, $file, $lineNumbers);
        $result = [];
        $skipped = 0;

        foreach ($records as $record) {
            if (!$this->matchesAll($record, $conditions)) {
                continue;
            }

            if ($skipped < $offset) {
                $skipped++;

                continue;
            }

            $result[] = $record;

            if ($limit !== null && \count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Processes foreign key actions for deleted records.
     *
     * @param array<int, array<string,null|scalar>> $deletedRecords
     */
    private function processForeignKeys(
        string $tableName,
        array $deletedRecords,
        string $event,
    ): void {
        $childRelations = $this->schema->getChildRelations($tableName);

        foreach ($childRelations as $relation) {
            $action = $event === 'delete'
                ? $relation->onDelete
                : $relation->onUpdate;

            if ($action === Schema\ForeignKeyActionEnum::NO_ACTION) {
                continue;
            }

            $parentValues = [];

            foreach ($deletedRecords as $record) {
                $val = $record[$relation->references] ?? null;

                if ($val !== null) {
                    $parentValues[] = $val;
                }
            }

            if ($parentValues === []) {
                continue;
            }

            $childTable = $relation->fromTable;
            $fk = $relation->foreignKey;
            $conditions = [
                new FilterCondition($fk, FilterOperatorEnum::IN, $parentValues),
            ];

            if ($action === Schema\ForeignKeyActionEnum::RESTRICT) {
                $count = $this->count($childTable, $conditions);

                if ($count > 0) {
                    throw StorageException::foreignKeyRestrict(
                        $childTable,
                        $fk,
                        $tableName,
                    );
                }
            } elseif ($action === Schema\ForeignKeyActionEnum::CASCADE) {
                $this->delete($childTable, $conditions);
            } elseif ($action === Schema\ForeignKeyActionEnum::SET_NULL) {
                $childRecords = $this->readAllRaw($childTable);
                $childSchema = $this->schema->getTable($childTable);
                $changed = false;

                foreach ($childRecords as &$childRecord) {
                    $childVal = $childRecord[$fk] ?? null;

                    if (
                        $childVal !== null
                        && \in_array($childVal, $parentValues, true)
                    ) {
                        $childRecord[$fk] = null;
                        $changed = true;
                    }
                }

                unset($childRecord);

                if ($changed) {
                    $this->writeAll($childTable, $childSchema, $childRecords);
                }
            }
        }
    }

    /**
     * Processes foreign key actions when a record is updated.
     *
     * @param array<string,null|scalar> $oldRecord
     * @param array<string,null|scalar> $newRecord
     */
    private function processForeignKeysOnUpdate(
        string $tableName,
        array $oldRecord,
        array $newRecord,
    ): void {
        $childRelations = $this->schema->getChildRelations($tableName);

        foreach ($childRelations as $relation) {
            $refField = $relation->references;
            $oldVal = $oldRecord[$refField] ?? null;
            $newVal = $newRecord[$refField] ?? null;

            if ($oldVal === $newVal) {
                continue;
            }

            $action = $relation->onUpdate;

            if ($action === Schema\ForeignKeyActionEnum::NO_ACTION) {
                continue;
            }

            if ($oldVal === null) {
                continue;
            }

            $childTable = $relation->fromTable;
            $fk = $relation->foreignKey;
            $conditions = [
                new FilterCondition($fk, FilterOperatorEnum::EQ, $oldVal),
            ];

            if ($action === Schema\ForeignKeyActionEnum::RESTRICT) {
                $count = $this->count($childTable, $conditions);

                if ($count > 0) {
                    throw StorageException::foreignKeyRestrict(
                        $childTable,
                        $fk,
                        $tableName,
                    );
                }
            } elseif ($action === Schema\ForeignKeyActionEnum::CASCADE) {
                $childRecords = $this->readAllRaw($childTable);
                $childSchema = $this->schema->getTable($childTable);
                $changed = false;

                foreach ($childRecords as &$childRecord) {
                    if (($childRecord[$fk] ?? null) === $oldVal) {
                        $childRecord[$fk] = $newVal;
                        $changed = true;
                    }
                }

                unset($childRecord);

                if ($changed) {
                    $this->writeAll($childTable, $childSchema, $childRecords);
                }
            } elseif ($action === Schema\ForeignKeyActionEnum::SET_NULL) {
                $childRecords = $this->readAllRaw($childTable);
                $childSchema = $this->schema->getTable($childTable);
                $changed = false;

                foreach ($childRecords as &$childRecord) {
                    if (($childRecord[$fk] ?? null) === $oldVal) {
                        $childRecord[$fk] = null;
                        $changed = true;
                    }
                }

                unset($childRecord);

                if ($changed) {
                    $this->writeAll($childTable, $childSchema, $childRecords);
                }
            }
        }
    }

    /**
     * @param array<string,null|scalar> $record
     */
    private function appendIndexes(
        TableSchema $tableSchema,
        array $record,
        int $lineNumber,
    ): void {
        if ($tableSchema->indexes !== []) {
            $this->indexManager->appendRecord(
                $tableSchema,
                $record,
                $lineNumber,
            );
        }
    }

    /**
     * @param array<int,OrderBy>         $ordering
     * @param array<int,FilterCondition> $conditions
     */
    private function resolveIndex(
        TableSchema $tableSchema,
        array $ordering,
        array $conditions,
    ): IndexSchema | null {
        if ($tableSchema->indexes === []) {
            return null;
        }

        if ($ordering !== []) {
            foreach ($tableSchema->indexes as $index) {
                if ($index->matchesOrdering($ordering)) {
                    return $index;
                }
            }
        }

        foreach ($conditions as $condition) {
            if (
                $condition->not
                || $condition->operator === FilterOperatorEnum::LIKE
            ) {
                continue;
            }

            foreach ($tableSchema->indexes as $index) {
                $firstField = $index->fields[0] ?? null;

                if (
                    $firstField !== null
                    && $firstField->field === $condition->field
                ) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Checks all unique constraints; $excludeId is the id of the record
     * being updated.
     *
     * @param array<int,array<string,null|scalar>> $existing
     * @param array<string,null|scalar>            $incoming
     */
    private function checkUniqueConstraints(
        TableSchema $tableSchema,
        array $existing,
        array $incoming,
        int | null $excludeId,
    ): void {
        foreach ($tableSchema->uniqueConstraints as $constraint) {
            $this->checkOneConstraint(
                $tableSchema,
                $constraint,
                $existing,
                $incoming,
                $excludeId,
            );
        }
    }

    /**
     * @param array<int,array<string,null|scalar>> $existing
     * @param array<string,null|scalar>            $incoming
     */
    private function checkOneConstraint(
        TableSchema $tableSchema,
        UniqueConstraint $constraint,
        array $existing,
        array $incoming,
        int | null $excludeId,
    ): void {
        $incomingKey = $constraint->keyOf($incoming);

        foreach ($existing as $record) {
            if (
                $excludeId !== null
                && isset($record['id'])
                && $record['id'] === $excludeId
            ) {
                continue;
            }

            if ($constraint->keyOf($record) === $incomingKey) {
                $fieldValues = array_map(
                    static fn (string $f): string => (string)(
                        $incoming[$f] ?? ''
                    ),
                    $constraint->fields,
                );

                throw StorageException::uniqueViolation(
                    $tableSchema->name,
                    implode(', ', $constraint->fields),
                    implode(', ', $fieldValues),
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
                throw StorageException::reorderColumnsUnknown(
                    $tableSchema->name,
                    $field,
                );
            }

            if (isset($seen[$field])) {
                throw StorageException::reorderColumnsDuplicate(
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

    /**
     * Normalizes a record against the schema contract:
     *  - keeps only fields declared in columns;
     *  - key order strictly follows columns order (id is always first);
     *  - schema fields missing from the input are added as null.
     *
     * @param array<string,null|scalar> $record
     *
     * @return array<string,null|scalar>
     */
    private function normalizeRecord(
        TableSchema $tableSchema,
        array $record,
    ): array {
        $normalized = [];

        foreach (array_keys($tableSchema->columns) as $column) {
            $normalized[$column] = $record[$column] ?? null;
        }

        return $normalized;
    }

    /**
     * @param array<string,null|scalar>  $record
     * @param array<int,FilterCondition> $conditions
     */
    private function matchesAll(array $record, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (!$condition->matches($record)) {
                return false;
            }
        }

        return true;
    }

    private function compareValues(
        bool | float | int | string | null $a,
        bool | float | int | string | null $b,
    ): int {
        if ($a === $b) {
            return 0;
        }

        if ($a === null) {
            return -1;
        }

        if ($b === null) {
            return 1;
        }

        if (\is_string($a) && \is_string($b)) {
            return strcmp($a, $b);
        }

        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            return $a <=> $b;
        }

        return strcmp((string)$a, (string)$b);
    }

    private function cacheKey(string $tableName): string
    {
        return 'table:' . $tableName;
    }

    private function validator(): IntegrityValidator
    {
        if ($this->validator === null) {
            $this->validator = new IntegrityValidator(
                $this->schema,
                $this->meta,
                $this->ndjson,
                $this->json,
                $this->indexManager,
            );
        }

        return $this->validator;
    }

    private function repairer(): IntegrityRepairer
    {
        if ($this->repairer === null) {
            $this->repairer = new IntegrityRepairer(
                $this->validator(),
                $this->schema,
                $this->meta,
                $this->ndjson,
                $this->json,
                $this->indexManager,
            );
        }

        return $this->repairer;
    }

    private function backupService(): Backup
    {
        if ($this->backup === null) {
            $this->backup = new Backup(
                $this->dbPath,
                $this->schema,
                $this->json,
                $this->ndjson,
            );
        }

        return $this->backup;
    }

    private function restoreService(): Restore
    {
        if ($this->restore === null) {
            $this->restore = new Restore(
                $this->backupService(),
                $this->schema,
                $this->meta,
                $this->ndjson,
                $this->indexManager,
            );
        }

        return $this->restore;
    }
}
