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
use AV\JsonProvider\Query\ComparisonMode;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Query\ValueComparator;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Relations\FkEngine;
use AV\JsonProvider\Schema\ColumnDefaults;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IdentifierRules;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Services\Backup\Backup;
use AV\JsonProvider\Services\Backup\Restore;
use AV\JsonProvider\Services\Integrity\IntegrityRepairer;
use AV\JsonProvider\Services\Integrity\IntegrityReport;
use AV\JsonProvider\Services\Integrity\IntegrityValidator;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\TableLockManager;
use AV\JsonProvider\Validation\ColumnTypeInfo;
use AV\JsonProvider\Validation\ValueValidator;
use Psr\Log\LoggerInterface;

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
    /**
     * Version of the cache entry format. Part of every cache key: bumping
     * it on a format change strands the old keys in a foreign namespace
     * (they expire by TTL/eviction) instead of serving stale shapes.
     */
    public const string CACHE_FORMAT_VERSION = '2';

    /** @var array<string,self> */
    private static array $instances = [];

    private readonly TableLockManager $locks;
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
    private FkEngine | null $fkEngine = null;
    private Backup | null $backup = null;
    private Restore | null $restore = null;
    private readonly string $dbPath;
    private readonly string $cacheNs;
    private readonly LoggerInterface | null $logger;
    private ComparisonMode $comparisonMode = ComparisonMode::Binary;

    private function __construct(
        string $dbPath,
        CacheInterface | null $cache = null,
        LoggerInterface | null $logger = null,
    ) {
        $this->logger = $logger;
        $this->dbPath = $dbPath;
        $real = realpath($dbPath);
        $this->cacheNs = 'jdp:' . self::CACHE_FORMAT_VERSION . ':'
            . substr(sha1($real === false ? $dbPath : $real), 0, 16) . ':';
        $this->locks = new TableLockManager($dbPath);
        $this->ndjson = new NdjsonStorage($dbPath, $this->locks);
        $this->json = new JsonStorage($dbPath, $this->locks);
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
     *
     * The path is normalized (trailing slashes stripped): "/db" and "/db/"
     * resolve to the same instance — two instances over one directory
     * would hold independent lock managers and block each other.
     *
     * $cache and $logger apply only when the instance is created by this
     * call; a live singleton keeps the ones it was built with.
     */
    public static function getInstance(
        string $dbPath,
        CacheInterface | null $cache = null,
        LoggerInterface | null $logger = null,
    ): self {
        $dbPath = self::normalizePath($dbPath);

        if (!isset(self::$instances[$dbPath])) {
            self::$instances[$dbPath] = new self($dbPath, $cache, $logger);
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
        return (new JsonStorage(self::normalizePath($dbPath)))
            ->exists('information_schema.json');
    }

    /**
     * Creates a new storage at the given path and returns its singleton.
     * Throws if the storage already exists.
     */
    public static function createDatabase(
        string $dbPath,
        CacheInterface | null $cache = null,
        LoggerInterface | null $logger = null,
    ): self {
        $dbPath = self::normalizePath($dbPath);
        $bootstrap = JsonStorage::createRoot($dbPath);
        $bootstrap->createFile(
            'information_schema.json',
            ['tables' => new \stdClass(), 'relations' => []],
        );
        $bootstrap->createObjectFile('meta.json');

        self::$instances[$dbPath] = new self($dbPath, $cache, $logger);

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
     * Sets the string comparison mode for ordering operators and ORDER BY
     * on this instance. Binary (default) is bytewise and index-compatible;
     * Locale orders string pairs via the intl Collator and excludes
     * indexes from string ordering/ranges (the byte-ordered index would
     * disagree). Equality operators are unaffected. Without ext-intl,
     * Locale silently behaves as Binary.
     */
    public function setComparisonMode(ComparisonMode $mode): self
    {
        $this->comparisonMode = $mode;

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
     * Creates a new table: registers it in the schema, initializes its meta
     * entry, then creates the NDJSON data file and empty files for every
     * declared index (so append on insert does not fail).
     *
     * Order: schema -> meta -> files. A crash mid-way leaves a registered
     * table without meta/files — a state the first write self-heals
     * (ensureTableConsistent) and repair fixes explicitly; the reverse
     * order would leave anonymous files invisible to both. Files are
     * provisioned via createFileFresh, so garbage left at the same path by
     * a crashed drop never leaks into the new table. A meta entry without a
     * schema entry is likewise an orphan of a crashed drop (dropTable
     * commits schema first) — under the held database EX lock it is
     * discarded before the fresh init, so the old id sequence and counters
     * never leak either.
     */
    public function createTable(TableSchema $tableSchema): void
    {
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
                }
            },
        );
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
     * children first if that matters. Service backing indexes the removed
     * relations provisioned in their CHILD tables are released with them
     * (the children are EX-locked for that); one left behind by a raced
     * schema change is caught by the fk_backing_index_orphaned validator
     * finding.
     */
    public function dropTable(string $tableName): void
    {
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
                $this->invalidateCache($tableName);

                $this->schema->unregisterTable($tableName);

                foreach ($orphanedRelations as $relation) {
                    if (
                        !$this->locks->isHeld($relation->childTable(), 'ex')
                    ) {
                        continue;
                    }

                    $this->releaseServiceBacking($relation);
                }

                $this->meta->dropEntry($tableName);
                $this->ndjson->deleteTable($tableName);
                $this->dtoRegistry->unregister($tableName);
                $this->locks->deleteTableLock($tableName);
            },
        );
    }

    /**
     * Renames a table: the schema key, every relation referencing the
     * table, its meta entry (counters preserved) and the physical
     * directory + data file all move to the new name. Index files keep
     * their names (they are named after the index, not the table). Runs
     * under the database EX lock plus table EX locks on BOTH names.
     *
     * Guards: $from must exist (TABLE_NOT_FOUND); $to must be a valid
     * identifier (INVALID_TABLE_NAME) and free in the schema, in meta and
     * on disk (TABLE_ALREADY_EXISTS).
     *
     * Crash model: a _pendingRename marker is written to meta.json FIRST,
     * then the schema (with relations) commits atomically, then meta and
     * the filesystem follow, and the marker is cleared last. repair()
     * reconciles a leftover marker deterministically BY THE SCHEMA STATE:
     * schema already holds $to — roll the meta/filesystem forward under
     * $to; schema still holds $from — nothing was renamed, drop the
     * marker.
     */
    public function renameTable(string $from, string $to): void
    {
        IdentifierRules::assertTableName($from);
        IdentifierRules::assertTableName($to);

        $this->locks->withLocks(
            [$from => 'ex', $to => 'ex'],
            'ex',
            function () use ($from, $to): void {
                $this->schema->reload();

                $pending = $this->meta->getPendingRename();

                if ($pending !== null) {
                    throw StorageException::renameIncomplete(
                        $pending['from'],
                        $pending['to'],
                    );
                }

                if (!$this->schema->hasTable($from)) {
                    throw StorageException::tableNotFound($from);
                }

                if (
                    $this->schema->hasTable($to)
                    || $this->meta->hasEntry($to)
                    || $this->ndjson->tableDirExists($to)
                ) {
                    throw StorageException::tableAlreadyExists($to);
                }

                $tableSchema = $this->schema->getTable($from);

                /*
                 * While the old name's version tag is still computable:
                 * after the meta entry moves and the files rename, the
                 * tag degrades and the warm entry of the last real state
                 * would survive under the old name.
                 */
                $this->invalidateCache($from);

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
                            ?? throw StorageException::tableNotFound($from);

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
                $this->ndjson->renameFile(
                    $to,
                    $tableSchema->getFileName(),
                    $to . '.ndjson',
                );

                $this->meta->clearPendingRename();

                $this->dtoRegistry->unregister($from);
                /*
                 * Defensive: a leftover entry of a PREVIOUS table that
                 * lived under $to could collide with the tag the renamed
                 * table now carries.
                 */
                $this->invalidateCache($to);
                $this->locks->deleteTableLock($from);
            },
        );
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
     * The method is about columns ONLY: the table's indexes, unique
     * constraints and comments stay exactly as they are — index and
     * constraint structure changes go through addIndex/dropIndex/
     * addUniqueConstraint/dropUniqueConstraint. Returns the added/dropped
     * column names; a no-op (empty lists) when columns and order already
     * match.
     *
     * Guards (all throw StorageException, nothing is written):
     *  - the table does not exist;
     *  - a retained column changes type — this method never re-encodes data, so
     *    a type change must be migrated separately;
     *  - an existing unique constraint or index references a column absent
     *    from $desired->columns — drop it first;
     *  - a non-nullable column with no zero-value default (temporal, year,
     *    month, day) is added to a non-empty table — declare it nullable.
     *
     * The full migrated record set is encode-probed BEFORE the schema or
     * any file is touched, so an unencodable stored value (foreign bytes in
     * the data file) aborts with the disk untouched. The schema is then
     * published before the data rewrite: a crash in between is healed by
     * repair toward the target state (added columns back-filled with their
     * type defaults).
     *
     * @return array{added: list<string>, dropped: list<string>}
     */
    public function migrateColumns(TableSchema $desired): array
    {
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

                $this->assertSchemaFieldsDeclared($target);

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
                $this->ensureTableConsistent($current);
                $records = $this->readAllForWrite($target->name);

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

                $this->createMissingIndexFiles($target);
                $this->schema->replaceTable($target);
                $this->writeAll($target->name, $target, $migrated);

                return ['added' => $added, 'dropped' => $dropped];
            },
        );
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
            $decoded[] = $this->projectOnSchema(
                $tableSchema,
                $this->values->decodeRecord($tableSchema, $record),
            );
        }

        return $decoded;
    }

    /**
     * Inserts a new record; id is assigned automatically (max + 1, minimum 1).
     * Returns the assigned id.
     *
     * Invariant: appends allocate monotonically growing ids, so the data
     * file is ordered by id. Before allocating, an O(1) guard compares the
     * id of the last stored line against the meta counter: a counter that
     * fell behind (meta.json restored from a backup, hand-edited) would
     * mint a duplicate primary key, so the watermark is first re-derived
     * from the data under the same EX lock. The guard leans on the
     * ordered-by-id invariant: a foreign edit that BOTH shuffles the file
     * (max id mid-file) AND rolls the counter back can slip past it — the
     * resulting duplicate is caught after the fact by the pk_duplicate
     * validator finding.
     *
     * @param array<string,null|scalar> $record
     */
    public function insert(string $tableName, array $record): int
    {
        return $this->locks->withLocks(
            $this->fkEngine()->insertLockPlan(
                $this->schema->getTable($tableName),
            ),
            'sh',
            function () use ($tableName, $record): int {
                $tableSchema = $this->schema->getTable($tableName);
                $record = $this->values->encodeForWrite(
                    $tableSchema,
                    $record,
                    true,
                );
                $this->ensureTableConsistent($tableSchema);

                if ($tableSchema->uniqueConstraints !== []) {
                    $records = $this->readAllForWrite($tableName);
                    $this->checkUniqueConstraints(
                        $tableSchema,
                        $records,
                        $record,
                        null,
                    );
                }

                $probe = $this->normalizeRecord(
                    $tableSchema,
                    $record + ['id' => 0],
                );

                $encoded = json_encode($probe, JSON_PRESERVE_ZERO_FRACTION);

                if ($encoded === false) {
                    throw StorageException::invalidRecord(
                        $tableSchema->name,
                        json_last_error_msg(),
                    );
                }

                $last = $this->ndjson->readLastLine(
                    $tableSchema->name,
                    $tableSchema->getFileName(),
                );

                if ($last !== null) {
                    $lastInserted = $this->meta
                        ->getLastInsertedId($tableName);

                    if ((int)($last['id'] ?? 0) >= $lastInserted + 1) {
                        $this->meta->setLastInsertedId(
                            $tableName,
                            max(
                                self::maxStoredId(
                                    $this->readAllForWrite($tableName),
                                ),
                                $lastInserted,
                            ),
                        );
                    }
                }

                $id = $this->meta->allocateId($tableName);
                $record['id'] = $id;
                $lineNumber = $this->meta->getLineCount($tableName);
                $record = $this->normalizeRecord($tableSchema, $record);

                $byteSize = $this->ndjson->append(
                    $tableSchema->name,
                    $tableSchema->getFileName(),
                    $record,
                );
                $this->appendIndexes($tableSchema, $record, $lineNumber);
                $this->meta->commitAppend(
                    $tableName,
                    $lineNumber + 1,
                    $byteSize,
                );
                $this->invalidateCache($tableName);

                return $id;
            },
        );
    }

    /**
     * Updates all records matching the given conditions with the same data
     * patch.
     * Returns the number of updated records (0 if no matches — empty match set
     * is not a failure). The 'id' key in $data is silently ignored.
     *
     * The FK closure (cascade/setNull patches, restrict probes) and the
     * unique checks of the final state are planned entirely before the
     * first write; the multi-table write set is then committed two-phase
     * (see FkEngine).
     *
     * @param array<int,FilterCondition> $conditions
     * @param array<string,null|scalar>  $data
     */
    public function update(
        string $tableName,
        array $conditions,
        array $data,
    ): int {
        return $this->withMutationLocks(
            $tableName,
            function (TableSchema $tableSchema) use (
                $conditions,
                $data,
            ): int {
                $tableName = $tableSchema->name;
                $conditions = $this->values->encodeConditions(
                    $tableSchema,
                    $conditions,
                );
                $this->ensureTableConsistent($tableSchema);
                $records = $this->readAllForWrite($tableName);

                unset($data['id']);
                $data = $this->values->encodeForWrite(
                    $tableSchema,
                    $data,
                    false,
                );

                $targetIndexes = [];

                foreach ($records as $index => $existing) {
                    if ($this->matchesAll($existing, $conditions)) {
                        $targetIndexes[] = $index;
                    }
                }

                if ($targetIndexes === []) {
                    return 0;
                }

                $plan = $this->fkEngine()->planFkUpdate(
                    $tableSchema,
                    $records,
                    $targetIndexes,
                    $data,
                );
                $this->fkEngine()->applyFkPlan($plan);

                return $plan->affected;
            },
        );
    }

    /**
     * Deletes records matching all conditions. Returns the number of deleted
     * records (0 if no matches — empty set is not a failure).
     *
     * The FK closure (cascades, setNull patches, restrict probes) is
     * planned entirely before the first write; the multi-table write set
     * is then committed two-phase, children before parents (see FkEngine).
     *
     * @param array<int,FilterCondition> $conditions
     */
    public function delete(string $tableName, array $conditions): int
    {
        return $this->withMutationLocks(
            $tableName,
            function (TableSchema $tableSchema) use ($conditions): int {
                $tableName = $tableSchema->name;
                $conditions = $this->values->encodeConditions(
                    $tableSchema,
                    $conditions,
                );
                $this->ensureTableConsistent($tableSchema);
                $records = $this->readAllForWrite($tableName);

                $deleteIndexes = [];

                foreach ($records as $index => $record) {
                    if ($this->matchesAll($record, $conditions)) {
                        $deleteIndexes[] = $index;
                    }
                }

                if ($deleteIndexes === []) {
                    return 0;
                }

                $plan = $this->fkEngine()->planFkDelete(
                    $tableSchema,
                    $records,
                    $deleteIndexes,
                );
                $this->fkEngine()->applyFkPlan($plan);

                return $plan->affected;
            },
        );
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

                $this->ensureTableConsistent($tableSchema);
                $records = $this->readAllForWrite($tableName);

                $this->ndjson->encodeRecords($tableName, array_map(
                    fn (array $r): array => $this->normalizeRecord(
                        $newSchema,
                        $r,
                    ),
                    $records,
                ));

                $this->schema->replaceTable($newSchema);
                $this->writeAll($tableName, $newSchema, $records);
            },
        );
    }

    /**
     * Removes every record of the table and resets its auto-increment
     * counter to 0 (SQL TRUNCATE semantics — the next insert gets id 1;
     * a delete-all keeps the counter). The data file is atomically
     * replaced with an empty one, every index is rebuilt empty, meta
     * committed and the cache invalidated. Runs under the database +
     * table EX locks.
     *
     * Foreign keys are NOT enforced (symmetric with dropTable): child FK
     * values referencing the truncated rows are left dangling — truncate
     * or migrate the children first if that matters.
     */
    public function truncate(string $tableName): void
    {
        $this->locks->withLocks(
            [$tableName => 'ex'],
            'ex',
            function () use ($tableName): void {
                $this->schema->reload();
                $tableSchema = $this->schema->getTable($tableName);

                /*
                 * Before the rewrite, while the old tag still resolves;
                 * afterwards it would compute the NEW tag and tear down
                 * the fresh entry writeAll just published.
                 */
                $this->invalidateCache($tableName);
                $this->ensureTableConsistent($tableSchema);
                $this->writeAll($tableName, $tableSchema, []);
                $this->meta->setLastInsertedId($tableName, 0);
            },
        );
    }

    /**
     * Renames a column: the schema (column key at the same position and
     * type, indexes, unique constraints, comments), every relation
     * referencing the column, and the stored data are all updated. Runs
     * under the database + table EX locks.
     *
     * Guards (nothing is written on failure): the column must exist
     * (COLUMN_NOT_FOUND), the primary key cannot be renamed
     * (PK_CONTRACT_VIOLATED), the new name must be a valid free identifier
     * (INVALID_COLUMN_NAME / COLUMN_ALREADY_EXISTS). The renamed record
     * set is encode-probed before any mutation.
     *
     * Crash model matches migrateColumns (schema first, data second) with
     * one caveat: repair converges to the NEW schema by back-filling the
     * renamed column with its type default rather than carrying the old
     * values over — take a backup before renaming if the data matters.
     *
     * A bound DTO map is recompiled against the new schema; if the DTO no
     * longer matches (its property still maps to the old name), the
     * binding is dropped so object reads fail loudly with
     * DTO_NOT_REGISTERED instead of hydrating garbage.
     *
     * Changing a column's TYPE is not supported by any mutation API — see
     * MIGRATE_COLUMN_TYPE_CHANGE: add a new nullable column, migrate the
     * values, drop the old column, then renameColumn.
     */
    public function renameColumn(
        string $tableName,
        string $from,
        string $to,
    ): void {
        $this->locks->withLocks(
            [$tableName => 'ex'],
            'ex',
            function () use ($tableName, $from, $to): void {
                $this->schema->reload();
                $tableSchema = $this->schema->getTable($tableName);

                if (!isset($tableSchema->columns[$from])) {
                    throw StorageException::columnNotFound($tableName, $from);
                }

                if ($from === PrimaryKey::FIELD) {
                    throw StorageException::pkContractViolated(
                        $tableName,
                        'the primary key column cannot be renamed',
                    );
                }

                IdentifierRules::assertColumnName($to);

                if (isset($tableSchema->columns[$to])) {
                    throw StorageException::columnAlreadyExists(
                        $tableName,
                        $to,
                    );
                }

                $newSchema = self::renameColumnInSchema(
                    $tableSchema,
                    $from,
                    $to,
                );

                $this->ensureTableConsistent($tableSchema);
                $records = $this->readAllForWrite($tableName);

                $renamed = [];

                foreach ($records as $record) {
                    $row = [];

                    foreach ($record as $key => $value) {
                        $row[$key === $from ? $to : $key] = $value;
                    }

                    $renamed[] = $row;
                }

                $this->ndjson->encodeRecords($tableName, array_map(
                    fn (array $r): array => $this->normalizeRecord(
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
                            throw StorageException::tableNotFound($tableName);
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

                $this->createMissingIndexFiles($newSchema);
                $this->writeAll($tableName, $newSchema, $renamed);

                /*
                 * writeAll has already built the index files of the NEW
                 * schema (including a renamed service backing); the file
                 * under the old service name is now an undeclared
                 * leftover — the same shape repair would sweep as an
                 * orphan, removed eagerly here.
                 */
                $oldServiceFile = IdentifierRules::serviceIndexNameFor($from)
                    . '.index.ndjson';
                $this->ndjson->deleteFile($tableName, $oldServiceFile);
                $this->recompileDto($tableName, $newSchema);
            },
        );
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
        $this->schema->updateTable(
            $tableName,
            static fn (TableSchema $t): TableSchema => $t
                ->withTableComment($comment),
        );
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
        $this->schema->updateTable(
            $tableName,
            static function (TableSchema $t) use (
                $tableName,
                $column,
                $comment,
            ): TableSchema {
                if (!isset($t->columns[$column])) {
                    throw StorageException::columnNotFound(
                        $tableName,
                        $column,
                    );
                }

                return $t->withColumnComment($column, $comment);
            },
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
        $this->schema->updateTable(
            $tableName,
            static function (TableSchema $t) use (
                $tableName,
                $comments,
                $merge,
            ): TableSchema {
                foreach (array_keys($comments) as $column) {
                    if (!isset($t->columns[$column])) {
                        throw StorageException::columnNotFound(
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

                $records = $this->readAllForWrite($tableName);
                $this->indexManager->rebuildOne(
                    $tableName,
                    $indexSchema,
                    $records,
                );
            },
        );
    }

    /**
     * Rebuilds every index of the table from current data, including PK,
     * and stamps the current index format. Sequential per index; each
     * rebuild atomically replaces its own file.
     */
    public function rebuildAllIndexes(string $tableName): void
    {
        $this->locks->withLocks(
            [$tableName => 'ex'],
            'sh',
            function () use ($tableName): void {
                $this->rebuildAllStamped($this->schema->getTable($tableName));
            },
        );
    }

    /**
     * Adds a secondary index to an existing table and builds its file from
     * current data, all under the database + table EX locks.
     *
     * Guards (nothing is written on failure): the table must exist, the
     * index name must be valid and free (INDEX_ALREADY_EXISTS), the PK
     * index cannot be added or replaced (PK_CONTRACT_VIOLATED), and every
     * indexed field must be a declared column
     * (MIGRATE_FIELD_UNKNOWN_COLUMN).
     *
     * Order: the file is provisioned and built first, the schema published
     * last — a crash in between leaves an undeclared file that validate()
     * reports as orphan and repair() removes. On a legacy-format table
     * every existing index is rebuilt with the current encoder and the
     * format stamped, so the table never mixes key formats.
     */
    public function addIndex(string $tableName, IndexSchema $index): void
    {
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
                    throw StorageException::pkContractViolated(
                        $tableName,
                        'the PK index cannot be added or replaced '
                            . 'via addIndex',
                    );
                }

                foreach ($tableSchema->indexes as $existing) {
                    if ($existing->name === $index->name) {
                        throw StorageException::indexAlreadyExists(
                            $tableName,
                            $index->name,
                        );
                    }
                }

                foreach ($index->fields as $field) {
                    if (!isset($tableSchema->columns[$field->field])) {
                        throw StorageException::migrateFieldUnknownColumn(
                            $tableName,
                            'index "' . $index->name . '"',
                            $field->field,
                        );
                    }
                }

                $this->ensureTableConsistent($tableSchema);
                $records = $this->readAllForWrite($tableName);

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

    /**
     * Drops a secondary index: removes it from the schema, then deletes
     * its file, under the database + table EX locks. The PK index cannot
     * be dropped (PK_CONTRACT_VIOLATED); an unknown name raises
     * INDEX_NOT_FOUND. Schema first, file second: a crash in between
     * leaves an orphan file that validate() reports and repair() removes.
     *
     * Service (FK backing) indexes cannot be dropped here — their
     * lifecycle belongs to the relation DDL (RESERVED_INDEX_NAME). A USER
     * index serving as the backing of a relation is dropped, but the FK
     * must not lose its probe: a service replacement is built first and
     * the relations are re-pointed to it in the same schema RMW that
     * removes the user index.
     */
    public function dropIndex(string $tableName, string $indexName): void
    {
        $this->locks->withLocks(
            [$tableName => 'ex'],
            'ex',
            function () use ($tableName, $indexName): void {
                $this->schema->reload();
                $tableSchema = $this->schema->getTable($tableName);

                if ($indexName === IndexSchema::PK_NAME) {
                    throw StorageException::pkContractViolated(
                        $tableName,
                        'the PK index cannot be dropped',
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
                    throw StorageException::indexNotFound(
                        $tableName,
                        $indexName,
                    );
                }

                if ($found->isPrimary) {
                    throw StorageException::pkContractViolated(
                        $tableName,
                        'the PK index cannot be dropped',
                    );
                }

                if ($found->isService) {
                    throw StorageException::reservedIndexName($indexName);
                }

                $replacement = $this->backingReplacementFor(
                    $tableName,
                    $found,
                );

                if ($replacement !== null) {
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
                            ?? throw StorageException::tableNotFound(
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

                $this->invalidateCache($tableName);
                $this->ndjson->deleteFile($tableName, $found->getFileName());
            },
        );
    }

    /**
     * Adds a unique constraint to an existing table under the database +
     * table EX locks. Existing data is pre-checked (type-strict keys, SQL
     * NULL semantics — records with a null key never conflict): a stored
     * duplicate raises UNIQUE_VIOLATION with nothing written. A taken name
     * raises UNIQUE_CONSTRAINT_ALREADY_EXISTS, an unknown field
     * MIGRATE_FIELD_UNKNOWN_COLUMN. Schema-only mutation — constraints
     * have no files.
     */
    public function addUniqueConstraint(
        string $tableName,
        UniqueConstraint $constraint,
    ): void {
        $this->locks->withLocks(
            [$tableName => 'ex'],
            'ex',
            function () use ($tableName, $constraint): void {
                $this->schema->reload();
                $tableSchema = $this->schema->getTable($tableName);

                foreach ($tableSchema->uniqueConstraints as $existing) {
                    if ($existing->name === $constraint->name) {
                        throw StorageException::uniqueConstraintAlreadyExists(
                            $tableName,
                            $constraint->name,
                        );
                    }
                }

                foreach ($constraint->fields as $field) {
                    if (!isset($tableSchema->columns[$field])) {
                        throw StorageException::migrateFieldUnknownColumn(
                            $tableName,
                            'unique constraint "' . $constraint->name . '"',
                            $field,
                        );
                    }
                }

                $seen = [];

                foreach ($this->readAllForWrite($tableName) as $record) {
                    $key = $constraint->keyOf($record);

                    if ($key === null) {
                        continue;
                    }

                    if (isset($seen[$key])) {
                        $fieldValues = array_map(
                            static fn (string $f): string => (string)(
                                $record[$f] ?? ''
                            ),
                            $constraint->fields,
                        );

                        throw StorageException::uniqueViolation(
                            $tableName,
                            implode(', ', $constraint->fields),
                            implode(', ', $fieldValues),
                        );
                    }

                    $seen[$key] = true;
                }

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

    /**
     * Drops a unique constraint by name under the database + table EX
     * locks. An unknown name raises UNIQUE_CONSTRAINT_NOT_FOUND.
     * Schema-only mutation.
     *
     * A single-column constraint that is the uniqueness ground of a
     * declared relation's referenced column cannot be dropped while the
     * relation exists (RELATION_REFERENCES_NOT_UNIQUE) — with duplicates
     * allowed in the parent column, a cascade would delete the children
     * of a still-living duplicate parent. Drop the relation first.
     */
    public function dropUniqueConstraint(
        string $tableName,
        string $name,
    ): void {
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
                    throw StorageException::uniqueConstraintNotFound(
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

    /**
     * Declares a relation (DDL, under the database EX + child table EX
     * locks): validates it against the fresh schema (tables and columns
     * exist, base types match, the referenced column is unique, onUpdate
     * is not declared on the PK, SET NULL lands on a nullable column),
     * rejects a canonical duplicate, provisions the FK backing index for
     * probing (cascade/restrict) actions, and persists. The relation is
     * active immediately — no restart or migration step.
     *
     * Backing resolution: an existing single-column USER index on the FK
     * column is reused; otherwise a service index "_fk_<column>" is built
     * (file first, then the schema RMW that also records the relation, so
     * a crash in between leaves only an orphan index file for repair to
     * sweep). Any backingIndex preset on the passed descriptor is
     * ignored — the engine owns that field.
     */
    public function addRelation(RelationSchema $relation): void
    {
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

    /**
     * Removes the relation(s) matching the declared (fromTable,
     * foreignKey, toTable) triple, under the database EX + child table EX
     * locks. Nothing matched — RELATION_NOT_FOUND. A service backing
     * index left without any other probing relation on the same child
     * column is dropped with the relation; a reused user index is always
     * kept.
     */
    public function dropRelation(
        string $fromTable,
        string $foreignKey,
        string $toTable,
    ): void {
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
            throw StorageException::relationNotFound(
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
     * Returns the declared relations: all of them, or (with a table name)
     * the ones involving that table on either side.
     *
     * @return array<int,RelationSchema>
     */
    public function relations(string | null $tableName = null): array
    {
        return $tableName === null
            ? $this->schema->getAllRelations()
            : $this->schema->getRelations($tableName);
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
        $this->locks->withLocks(
            [$tableName => 'ex'],
            'sh',
            function () use ($tableName): void {
                $this->repairer()->optimizeTable($tableName);
                $this->invalidateCache($tableName);
            },
        );
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
        return $this->locks->withLocks(
            [$tableName => 'ex'],
            'sh',
            function () use ($tableName): IntegrityReport {
                $report = $this->repairer()->repairTable($tableName);
                $this->invalidateCache($tableName);

                return $report;
            },
        );
    }

    /**
     * Repairs the entire database where possible; returns the full report.
     * Runs under the database EX lock plus EX on every schema table.
     * All per-table caches are invalidated.
     */
    public function repair(): IntegrityReport
    {
        return $this->locks->withDatabase(
            function (): IntegrityReport {
                $this->schema->reload();
                $plan = array_fill_keys(
                    array_keys($this->schema->getTables()),
                    'ex',
                );

                return $this->locks->withLocks(
                    $plan,
                    null,
                    function (): IntegrityReport {
                        $report = $this->repairer()->repairDatabase();

                        foreach (
                            array_keys($this->schema->getTables()) as $table
                        ) {
                            $this->invalidateCache($table);
                        }

                        return $report;
                    },
                );
            },
        );
    }

    /**
     * Exports the current DB state to a .tar.gz archive at $destination.
     * Returns the absolute path of the created archive. The export runs
     * under the database EX lock, so all tables come from one committed
     * generation; the manifest carries per-table id counters and sha256
     * checksums of every member.
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
     * Restores DB state from a .tar.gz archive previously produced by
     * backup(). Member checksums from the manifest are verified before
     * anything is applied. By default the archive's table set must exactly
     * match the current schema; with $adoptArchivedSchema the archived
     * schema replaces the current one (tables present locally but absent
     * from the archive are dropped only under $pruneExtraTables). On any
     * failure mid-restore, the original DB state — schema, data and
     * counters — is rolled back from a safety snapshot taken automatically
     * before the restore begins. The restore service serializes the whole
     * operation under the database EX lock plus table EX locks.
     *
     * Caches are invalidated BEFORE the mutation, while the version tags
     * are still computable (rewrites land on fresh inodes anyway, but a
     * pruned table loses its meta and file — its tag must be dropped
     * while it still resolves), and defensively after.
     */
    public function restore(
        string $archivePath,
        bool $adoptArchivedSchema = false,
        bool $pruneExtraTables = false,
    ): void {
        foreach (array_keys($this->schema->getTables()) as $table) {
            $this->invalidateCache($table);
        }

        $this->restoreService()->restore(
            $archivePath,
            $adoptArchivedSchema,
            $pruneExtraTables,
        );
        $this->schema->reload();

        foreach (array_keys($this->schema->getTables()) as $table) {
            $this->invalidateCache($table);
        }
    }

    /**
     * Selects records by conditions, ordering, and pagination.
     * Uses an ordering-index if available, then a filter-index, otherwise
     * falls back to full scan.
     *
     * Two-tier read model: a full scan is lock-free — rename-atomicity
     * guarantees it sees one complete file (the snapshot may predate
     * appends that finish after the file was opened). An index-driven read
     * holds the table SH lock across the index+data I/O, so a writer (table
     * EX over data -> indexes -> meta) can never swap the files between the
     * index lookup and the row reads — the pair is always coherent. The SH
     * section covers only the I/O and is released before decoding.
     *
     * The index is used only when trusted (indexTrustworthy: committed
     * byteSize matches the data file, indexFormat >= 2); an untrusted
     * index silently degrades to a full scan, structural corruption of a
     * trusted index throws INDEX_UNRELIABLE. An empty index result is
     * authoritative only after that validation.
     *
     * Result pipeline invariant: filter -> sort -> distinct ->
     * array_slice(offset, limit) -> decodeRecord. Index-side pagination is
     * an optimization allowed only when it cannot change this outcome
     * (matching ordering index, no distinct).
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

        if ($limit !== null && $limit < 0) {
            throw StorageException::invalidLimit($tableName, $limit);
        }

        if ($offset < 0) {
            throw StorageException::invalidOffset($tableName, $offset);
        }

        $this->assertKnownColumns($tableSchema, $ordering, $distinctFields);
        $conditions = $this->values->encodeConditions(
            $tableSchema,
            $conditions,
        );
        $index = $this->resolveIndex($tableSchema, $ordering, $conditions);
        $paginatedByIndex = false;
        $records = null;

        if ($index !== null) {
            $appliedPagination = false;

            /**
             * The pre-lock resolution is only a hint: the schema is re-read
             * and the index re-resolved under the SH lock, so a concurrent
             * DDL that dropped or replaced the index degrades this read to
             * a full scan instead of failing on a missing index file. Null
             * from the closure signals that fallback — an untrusted index
             * (stale byteSize, pre-v2 format) degrades the same way, while
             * structural corruption of a v2 index throws INDEX_UNRELIABLE.
             *
             * With distinct fields the index may only order and filter:
             * pagination must happen after dedup, so limit/offset are never
             * pushed into the index path (invariant shared with
             * q-distinct-pagination — degradation must not bring it back).
             *
             * @var null|array<int,array<string,null|scalar>> $records
             */
            $records = $this->locks->withLocks(
                [$tableName => 'sh'],
                null,
                function () use (
                    $tableName,
                    $conditions,
                    $ordering,
                    $limit,
                    $offset,
                    $distinctFields,
                    &$appliedPagination,
                ): array | null {
                    $freshSchema = $this->schema->getTable($tableName);
                    $freshIndex = $this->resolveIndex(
                        $freshSchema,
                        $ordering,
                        $conditions,
                    );

                    if ($freshIndex === null) {
                        return null;
                    }

                    if (
                        $ordering !== []
                        && $freshIndex->matchesOrdering($ordering)
                        && $this->orderingIndexable($freshSchema, $ordering)
                        && $distinctFields === []
                    ) {
                        $appliedPagination = true;

                        return $this->selectViaIndex(
                            $freshIndex,
                            $freshSchema,
                            $conditions,
                            $ordering,
                            $limit,
                            $offset,
                        );
                    }

                    return $this->selectViaIndex(
                        $freshIndex,
                        $freshSchema,
                        $conditions,
                        $ordering,
                    );
                },
            );

            $paginatedByIndex = $records !== null && $appliedPagination;
        }

        if ($records === null) {
            $records = $this->selectFullScan(
                $tableName,
                $conditions,
                $ordering,
            );
        }

        if ($distinctFields !== []) {
            $seen = [];
            $deduped = [];

            foreach ($records as $record) {
                /*
                 * serialize() is type-distinguishing: null, '', false, 0,
                 * '0', true, 1 and '1' are eight distinct keys, and \x00
                 * bytes inside values cannot collide tuples. Missing
                 * fields read as null (ghost rows); unknown fields were
                 * rejected by assertKnownColumns earlier.
                 */
                $key = serialize(array_map(
                    static fn (string $f): mixed => $record[$f] ?? null,
                    $distinctFields,
                ));

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $deduped[] = $record;
                }
            }

            $records = $deduped;
        }

        if (!$paginatedByIndex && ($offset > 0 || $limit !== null)) {
            $records = \array_slice($records, $offset, $limit);
        }

        $decoded = [];

        foreach ($records as $record) {
            $decoded[] = $this->projectOnSchema(
                $tableSchema,
                $this->values->decodeRecord($tableSchema, $record),
            );
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
        $conditions = $this->values->encodeConditions(
            $tableSchema,
            $conditions,
        );
        $records = $this->readAllRaw($tableName);

        if ($conditions === []) {
            return \count($records);
        }

        return \count(array_filter(
            $records,
            fn (array $r): bool => $this->matchesAll($r, $conditions),
        ));
    }

    /**
     * Invalidates the cache entry of the table's CURRENT version tag —
     * the manual escape hatch for the documented same-size-update blind
     * spot of the version-tagged keys; entries under other (stale) tags
     * are already unreachable and expire by TTL/eviction.
     */
    public function invalidateCache(string $tableName): void
    {
        $this->cache->invalidate($this->cacheKey(
            $tableName,
            $this->tableVersionTag($tableName),
        ));
    }

    /**
     * Removes every cache entry of THIS database from the backend (the
     * per-database key namespace scopes the flush) — other databases and
     * other applications sharing the pool are untouched.
     */
    public function flushDb(): void
    {
        $this->cache->flushDb($this->cacheNs);
    }

    /**
     * Drops every key the schema does not declare from a record about to
     * be returned to the caller: ghost fields of a raw stored line (a
     * column dropped from the schema, a hand-added key) must not leak
     * into query results on either read path. Keys are dropped only —
     * a missing column stays missing (the ghost-row null semantics of
     * the filter layer).
     *
     * The equal-count fast path skips the O(columns) intersect for the
     * overwhelmingly common clean record: provider writes always store
     * exactly the schema columns (normalizeRecord), so a ghost key only
     * comes from a foreign edit, and adding one grows the count and takes
     * the projecting branch. The single residual — a same-count key SWAP
     * (a ghost replacing a schema column) — is a corruption the validator
     * independently surfaces as record_key_order; trading it away avoids a
     * 4-6x per-row cost on every read.
     *
     * @param array<string,null|scalar> $record
     *
     * @return array<string,null|scalar>
     */
    private function projectOnSchema(
        TableSchema $tableSchema,
        array $record,
    ): array {
        if (\count($record) === \count($tableSchema->columns)) {
            return $record;
        }

        return array_intersect_key($record, $tableSchema->columns);
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
                    throw StorageException::migrateFieldUnknownColumn(
                        $tableName,
                        'relation ' . $relation->fromTable . '('
                            . $relation->foreignKey . ') -> '
                            . $relation->toTable
                            . ' (drop the relation first)',
                        $column,
                    );
                }
            }
        }
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
                throw StorageException::relationReferencesNotUnique(
                    $tableSchema->name,
                    $column,
                );
            }
        }
    }

    /**
     * Runs a mutation body under the FK-aware lock plan of the table,
     * closing the plan-staleness window: the plan is computed from the
     * pre-lock schema snapshot, and a relation added by a concurrent
     * addRelation (db EX) between planning and the db SH acquisition
     * could make the engine cascade into a table the frame never locked.
     * The body therefore re-derives the plan from the fresh in-section
     * schema and retries with the new plan when the held set no longer
     * covers it; under the held db SH lock the schema cannot change
     * again, so a covered plan stays covered for the whole section.
     *
     * @template T
     *
     * @param callable(TableSchema): T $body
     *
     * @return T
     */
    private function withMutationLocks(string $tableName, callable $body)
    {
        $attempts = 0;

        while (true) {
            $plan = $this->fkEngine()->mutationLockPlan(
                $this->schema->getTable($tableName),
            );

            $outcome = $this->locks->withLocks(
                $plan,
                'sh',
                function () use ($tableName, $body): array | null {
                    $tableSchema = $this->schema->getTable($tableName);
                    $freshPlan = $this->fkEngine()->mutationLockPlan(
                        $tableSchema,
                    );

                    foreach ($freshPlan as $table => $mode) {
                        if (!$this->locks->isHeld($table, $mode)) {
                            return null;
                        }
                    }

                    return [$body($tableSchema)];
                },
            );

            if ($outcome !== null) {
                return $outcome[0];
            }

            if (++$attempts >= 5) {
                throw StorageException::lockOrderViolation(
                    'mutation lock plan for table "' . $tableName
                        . '" kept going stale (concurrent relation DDL)',
                );
            }
        }
    }

    /**
     * When the index being dropped is the backing of at least one probing
     * relation on this child table, returns the service index descriptor
     * that must replace it (or an already existing service index on the
     * same column); null when no relation depends on it.
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

        return new IndexSchema(
            name: IdentifierRules::serviceIndexNameFor($column),
            fields: [
                new Schema\IndexFieldSchema(
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
        $this->ensureTableConsistent($tableSchema);
        $records = $this->readAllForWrite($tableName);

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
     * an existing single-column USER index on that column is reused;
     * otherwise the service descriptor "_fk_<column>" is returned (which
     * may itself already be declared by another relation on the same
     * column and is then shared).
     */
    private function resolveBackingIndex(
        string $childTable,
        string $column,
    ): IndexSchema {
        $tableSchema = $this->schema->getTable($childTable);

        foreach ($tableSchema->indexes as $index) {
            if (
                !$index->isService
                && !$index->isPrimary
                && \count($index->fields) === 1
                && $index->fields[0]->field === $column
            ) {
                return $index;
            }
        }

        return new IndexSchema(
            name: IdentifierRules::serviceIndexNameFor($column),
            fields: [
                new Schema\IndexFieldSchema(
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
     * Drops the SERVICE backing index of a removed relation when no other
     * probing relation on the same child still points to it. User indexes
     * reused as backing are never dropped here.
     */
    private function releaseServiceBacking(RelationSchema $removed): void
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
     * Rebuilds every index of the table with the current encoder and
     * stamps indexFormat 2. A single-index rebuild on a pre-v2 table
     * escalates here: rebuilding one file in the new format while the
     * rest stay v1 would poison the per-table format marker. Requires
     * the table EX lock.
     */
    private function rebuildAllStamped(TableSchema $tableSchema): void
    {
        $records = $this->readAllForWrite($tableSchema->name);

        $this->indexManager->rebuild($tableSchema, $records);

        if ($this->meta->getIndexFormat($tableSchema->name) < 2) {
            $this->meta->stampIndexFormat($tableSchema->name, 2);
        }
    }

    /**
     * Strips trailing slashes so every spelling of a directory maps to one
     * singleton (and one lock manager). The filesystem root stays "/".
     */
    private static function normalizePath(string $dbPath): string
    {
        $normalized = rtrim(str_replace('\\', '/', $dbPath), '/');

        return $normalized === '' ? '/' : $normalized;
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
                $fields[] = new Schema\IndexFieldSchema(
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
        } catch (StorageException) {
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

            if (!ColumnDefaults::hasSafeDefault($type)) {
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
     * Reads all records straight from disk, bypassing the cache entirely —
     * the only legal base for a rewrite or a constraint check inside a
     * write critical section. The caller must hold the appropriate table
     * lock; the cache is neither read nor written. Float columns are
     * widened (widenFloats), so unique keys and FK probes compare the same
     * PHP types the read paths surface.
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function readAllForWrite(string $tableName): array
    {
        $tableSchema = $this->schema->getTable($tableName);
        $records = $this->ndjson->read(
            $tableName,
            $tableSchema->getFileName(),
        );
        $this->assertStoredColumnNames($records);

        return $this->values->widenFloats($tableSchema, $records);
    }

    /**
     * Cheap corruption tripwire on data load: the keys of the first stored
     * record must be valid column identifiers. Every record the provider
     * ever writes is normalized against the schema (whose column names are
     * validated), so an invalid key can only come from foreign tampering
     * with the data file.
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    private function assertStoredColumnNames(array $records): void
    {
        $first = $records[0] ?? null;

        if ($first === null) {
            return;
        }

        foreach (array_keys($first) as $column) {
            IdentifierRules::assertColumnName($column);
        }
    }

    /**
     * Reads all records in their stored (canonical UTC) form, with cache.
     *
     * This is the internal read path: it feeds full-scan selects and count —
     * consumers that must see the exact stored values, never the
     * timezone-localized presentation. Write paths use readAllForWrite()
     * instead: the cache is never a base for a rewrite. Public reads go
     * through readAll()/select(), which decode temporal columns at the very
     * end. Float columns are widened before the records reach the cache,
     * so every cache adapter stores and serves the same PHP types a cold
     * disk read would produce.
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function readAllRaw(string $tableName): array
    {
        $cacheKey = $this->cacheKey(
            $tableName,
            $this->tableVersionTag($tableName),
        );
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $tableSchema = $this->schema->getTable($tableName);
        $raw = $this->ndjson->read($tableName, $tableSchema->getFileName());
        $this->assertStoredColumnNames($raw);
        $records = $this->values->widenFloats($tableSchema, $raw);

        /*
         * Lock-free set: a writer committing between the tag computation
         * and this read can only make the entry hold a NEWER state than
         * its tag claims — and the tag itself leaves circulation with the
         * writer's meta commit, so no reader keeps resolving to it. The
         * cache never travels back in time.
         */
        $this->cache->set($cacheKey, $records);

        return $records;
    }

    /**
     * Sorts records in place by the ordering rules (stable — usort in
     * PHP 8+).
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
     * O(1) consistency gate run as the first step of a write operation:
     * compares the committed meta byteSize against the actual data file
     * size. On match the table is trusted as-is. On mismatch (crashed
     * append, foreign write, pre-byteSize meta) the table is re-emitted
     * canonically: records are parsed from disk (a complete unterminated
     * tail record survives the parse; a torn partial line was never
     * acknowledged and is dropped), the file is fully rewritten so parsed
     * positions and physical line numbers realign, indexes are rebuilt
     * against those positions and the true lineCount/byteSize committed.
     * A tail-only patch would be cheaper but leaves index line numbers
     * pointing at physical lines that a mid-file garbage line has shifted.
     * Requires the table EX lock (via writeAll).
     *
     * When the meta entry itself was missing and had to be initialized, the
     * id watermark (lastInsertedId = max stored id) is restored BEFORE the
     * counters are committed by the rewrite: a crash after commitRewrite
     * would otherwise leave a green byteSize gate over lastInsertedId=0 and
     * the next insert would mint a duplicate primary key, while a crash in
     * this order leaves a red gate and healing simply re-runs.
     */
    private function ensureTableConsistent(TableSchema $tableSchema): void
    {
        if (
            !$this->ndjson->exists(
                $tableSchema->name,
                $tableSchema->getFileName(),
            )
        ) {
            /*
             * A data file missing while a pending-rename marker involves
             * this table is the renameTable crash window: the real data
             * still lives under the OLD file name. Provisioning a fresh
             * empty file here would block the repair roll-forward and
             * turn the stranded file into an "orphan" — refuse loudly
             * instead and let repair() reconcile first.
             */
            $pending = $this->meta->getPendingRename();

            if (
                $pending !== null
                && ($pending['from'] === $tableSchema->name
                    || $pending['to'] === $tableSchema->name)
            ) {
                throw StorageException::renameIncomplete(
                    $pending['from'],
                    $pending['to'],
                );
            }

            $this->ndjson->createFileFresh(
                $tableSchema->name,
                $tableSchema->getFileName(),
            );
        }

        $this->createMissingIndexFiles($tableSchema);

        $metaInitialized = false;

        try {
            $expected = $this->meta->getByteSize($tableSchema->name);
        } catch (StorageException $e) {
            /*
             * Both a missing and a corrupt entry self-heal the same way:
             * the counters are fully derivable from the data, so the
             * entry is re-initialized and the rewrite below re-commits
             * the true lineCount/byteSize (with the id watermark
             * restored first).
             */
            if ($e->getErrorKey() === 'META_ENTRY_CORRUPT') {
                $this->meta->dropEntry($tableSchema->name);
            } elseif ($e->getErrorKey() !== 'META_ENTRY_MISSING') {
                throw $e;
            }

            $this->meta->initTable($tableSchema->name);
            $metaInitialized = true;
            $expected = 0;
        }

        $actual = $this->ndjson->fileSizeBytes(
            $tableSchema->name,
            $tableSchema->getFileName(),
        );

        if (!$metaInitialized && $expected === $actual) {
            if ($this->meta->getIndexFormat($tableSchema->name) >= 2) {
                return;
            }

            $this->indexManager->rebuild(
                $tableSchema,
                $this->readAllForWrite($tableSchema->name),
            );
            $this->meta->stampIndexFormat($tableSchema->name, 2);

            return;
        }

        $records = $this->readAllForWrite($tableSchema->name);

        if ($metaInitialized) {
            $maxId = self::maxStoredId($records);

            if ($maxId > 0) {
                $this->meta->setLastInsertedId($tableSchema->name, $maxId);
            }
        }

        $this->writeAll($tableSchema->name, $tableSchema, $records);
    }

    /**
     * The largest stored primary key across the records (0 when none) —
     * the id watermark restored into meta when the counter is missing or
     * fell behind the data.
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    private static function maxStoredId(array $records): int
    {
        $max = 0;

        foreach ($records as $record) {
            $id = $record[PrimaryKey::FIELD] ?? null;

            if (\is_int($id) && $id > $max) {
                $max = $id;
            }
        }

        return $max;
    }

    /**
     * Records are float-widened before hitting both the disk and the cache:
     * the disk write then preserves the zero fraction (99.0 stays "99.0")
     * and every cache adapter holds the same PHP types a cold read yields.
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    private function writeAll(
        string $tableName,
        TableSchema $tableSchema,
        array $records,
    ): void {
        if (!$this->locks->isHeld($tableName, 'ex')) {
            throw StorageException::writeLockRequired('writeAll', $tableName);
        }

        $records = $this->values->widenFloats($tableSchema, array_values(
            array_map(
                fn (array $r): array => $this->normalizeRecord(
                    $tableSchema,
                    $r,
                ),
                $records,
            ),
        ));
        $byteSize = $this->ndjson->write(
            $tableSchema->name,
            $tableSchema->getFileName(),
            $records,
        );
        $this->indexManager->rebuild($tableSchema, $records);
        $this->meta->commitRewrite($tableName, \count($records), $byteSize);

        if ($this->meta->getIndexFormat($tableName) < 2) {
            $this->meta->stampIndexFormat($tableName, 2);
        }

        $this->publishCacheEntry($tableName, \count($records), $records);
    }

    /**
     * Publishes records under the tag of the state committed by THIS
     * writer: the lineCount is the just-committed value (re-reading
     * meta.json could pick up a foreign later state), the size and inode
     * come from a stat of the file written moments ago under the still
     * held table EX lock — no concurrent writer can slip a different
     * file underneath within the critical section.
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    private function publishCacheEntry(
        string $tableName,
        int $lineCount,
        array $records,
    ): void {
        try {
            $stat = $this->ndjson->fileStat(
                $tableName,
                $tableName . '.ndjson',
            );
        } catch (StorageException) {
            return;
        }

        $this->cache->set(
            $this->cacheKey(
                $tableName,
                $lineCount . '-' . $stat['size'] . '-' . $stat['ino'],
            ),
            $records,
        );
    }

    /**
     * Selects records using an index, or returns null to degrade to a
     * full scan.
     *
     * The trust gate runs first: a stale index (committed byteSize differs
     * from the data file) or a pre-v2 format returns null silently — the
     * next write under the table EX lock rebuilds and stamps it. A trusted
     * (v2) index is then read with full structural validation; corruption
     * throws INDEX_UNRELIABLE rather than serving wrong rows.
     *
     * Ordering-index: reads lines in index order, then applies conditions.
     * Filter-index: reads only the lines found via index search, then
     * applies all conditions.
     *
     * @param array<int,FilterCondition> $conditions
     * @param array<int,OrderBy>         $ordering
     *
     * @return null|array<int,array<string,null|scalar>>
     */
    private function selectViaIndex(
        IndexSchema $index,
        TableSchema $tableSchema,
        array $conditions,
        array $ordering,
        int | null $limit = null,
        int $offset = 0,
    ): array | null {
        try {
            return $this->selectViaIndexTrusted(
                $index,
                $tableSchema,
                $conditions,
                $ordering,
                $limit,
                $offset,
            );
        } catch (StorageException $e) {
            if ($e->getErrorKey() === 'INDEX_UNRELIABLE') {
                $this->logger?->error($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * The trusted-index read pipeline behind selectViaIndex; every
     * INDEX_UNRELIABLE it raises is logged at error level by the wrapper.
     *
     * @param array<int,FilterCondition> $conditions
     * @param array<int,OrderBy>         $ordering
     *
     * @return null|array<int,array<string,null|scalar>>
     */
    private function selectViaIndexTrusted(
        IndexSchema $index,
        TableSchema $tableSchema,
        array $conditions,
        array $ordering,
        int | null $limit = null,
        int $offset = 0,
    ): array | null {
        if (!$this->indexTrustworthy($tableSchema)) {
            return null;
        }

        $entries = $this->indexManager->readIndexValidated(
            $tableSchema->name,
            $index,
            $this->meta->getLineCount($tableSchema->name),
            true,
        );

        if ($entries === null) {
            return null;
        }

        if (
            $ordering !== []
            && $index->matchesOrdering($ordering)
            && $this->orderingIndexable($tableSchema, $ordering)
        ) {
            $lineNumbers = array_column($entries, 'line');

            if ($conditions === []) {
                if ($offset > 0 || $limit !== null) {
                    $lineNumbers = \array_slice($lineNumbers, $offset, $limit);
                }

                $records = $this->values->widenFloats(
                    $tableSchema,
                    $this->ndjson->readLines(
                        $tableSchema->name,
                        $tableSchema->getFileName(),
                        $lineNumbers,
                    ),
                );

                if (\count($records) !== \count($lineNumbers)) {
                    throw StorageException::indexUnreliable(
                        $tableSchema->name,
                        $index->name,
                        'indexed lines are missing from the data file',
                    );
                }

                return $records;
            }

            return $this->readFilteredPaginated(
                $tableSchema,
                $lineNumbers,
                $conditions,
                $offset,
                $limit,
            );
        }

        $lineNumbers = null;

        foreach ($conditions as $condition) {
            if (!$this->conditionIndexServable($tableSchema, $condition)) {
                continue;
            }

            $lines = $this->indexManager->searchLines(
                $tableSchema,
                $entries,
                $index,
                $condition,
            );

            if ($lines !== null) {
                $lineNumbers = $lines;
                break;
            }
        }

        if ($lineNumbers !== null) {
            sort($lineNumbers);
        }

        $records = $this->values->widenFloats(
            $tableSchema,
            $lineNumbers !== null
                ? $this->ndjson->readLines(
                    $tableSchema->name,
                    $tableSchema->getFileName(),
                    $lineNumbers,
                )
                : $this->ndjson->read(
                    $tableSchema->name,
                    $tableSchema->getFileName(),
                ),
        );

        if ($conditions !== []) {
            $records = array_values(array_filter(
                $records,
                fn (array $r): bool => $this->matchesAll($r, $conditions),
            ));
        }

        if ($ordering !== []) {
            $this->sortByOrdering($records, $ordering);
        }

        return $records;
    }

    /**
     * Lock-free full scan: reads all records (via cache), filters and
     * sorts. The fallback for every query an index cannot serve.
     *
     * @param array<int,FilterCondition> $conditions
     * @param array<int,OrderBy>         $ordering
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function selectFullScan(
        string $tableName,
        array $conditions,
        array $ordering,
    ): array {
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

        return $records;
    }

    /**
     * O(1) read-side trust gate for the table's indexes: they are used
     * only when the committed byteSize matches the actual data file (the
     * indexes describe exactly the committed state) and the on-disk key
     * format is current. Any doubt — missing meta, unknown byteSize,
     * foreign append, legacy format — degrades reads to a full scan; the
     * next write heals and stamps under the table EX lock.
     */
    private function indexTrustworthy(TableSchema $tableSchema): bool
    {
        try {
            $byteSize = $this->meta->getByteSize($tableSchema->name);
            $format = $this->meta->getIndexFormat($tableSchema->name);
        } catch (StorageException) {
            return false;
        }

        if ($format < 2) {
            $this->logger?->info(
                'table "' . $tableSchema->name . '": pre-v2 index format, '
                    . 'queries fall back to full scans until the next '
                    . 'write rebuilds and stamps the indexes',
            );

            return false;
        }

        if ($byteSize === null) {
            return false;
        }

        if (
            !$this->ndjson->exists(
                $tableSchema->name,
                $tableSchema->getFileName(),
            )
        ) {
            return false;
        }

        if (
            $byteSize !== $this->ndjson->fileSizeBytes(
                $tableSchema->name,
                $tableSchema->getFileName(),
            )
        ) {
            $this->logger?->info(
                'table "' . $tableSchema->name . '": committed byteSize '
                    . 'differs from the data file (foreign append or stale '
                    . 'indexes), queries fall back to full scans until the '
                    . 'next write heals the drift',
            );

            return false;
        }

        return true;
    }

    /**
     * Reads records by lineNumbers, widens float columns, filters, applies
     * offset/limit without loading all into memory.
     *
     * @param array<int,int>             $lineNumbers
     * @param array<int,FilterCondition> $conditions
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function readFilteredPaginated(
        TableSchema $tableSchema,
        array $lineNumbers,
        array $conditions,
        int $offset,
        int | null $limit,
    ): array {
        $records = $this->values->widenFloats(
            $tableSchema,
            $this->ndjson->readLines(
                $tableSchema->name,
                $tableSchema->getFileName(),
                $lineNumbers,
            ),
        );
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
     * Rejects orderBy and distinct references to columns absent from the
     * schema — together with the where-side check in encodeConditions
     * this closes the "typo deletes the whole table" class: no query
     * layer input reaches matching with an unknown column name.
     *
     * @param array<int,OrderBy> $ordering
     * @param array<int,string>  $distinctFields
     */
    private function assertKnownColumns(
        TableSchema $tableSchema,
        array $ordering,
        array $distinctFields,
    ): void {
        foreach ($ordering as $order) {
            if (!\array_key_exists($order->field, $tableSchema->columns)) {
                throw StorageException::queryUnknownColumn(
                    $tableSchema->name,
                    $order->field,
                    'orderBy',
                );
            }
        }

        foreach ($distinctFields as $field) {
            if (!\array_key_exists($field, $tableSchema->columns)) {
                throw StorageException::queryUnknownColumn(
                    $tableSchema->name,
                    $field,
                    'distinct',
                );
            }
        }
    }

    /**
     * Picks an index for the query: first one matching the requested
     * ordering, then one whose first field is filtered by an indexable
     * condition. Service (FK backing) indexes are invisible here — they
     * exist for FK probes only, and a select over the FK column behaves
     * exactly as if the column were unindexed.
     *
     * In Locale comparison mode the byte-ordered index disagrees with the
     * collator, so string columns are excluded from index-driven ordering
     * and ranges; string EQ/IN stay indexable (equality is byte-exact in
     * both modes).
     *
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

        if (
            $ordering !== []
            && $this->orderingIndexable($tableSchema, $ordering)
        ) {
            foreach ($tableSchema->indexes as $index) {
                if ($index->isService) {
                    continue;
                }

                if ($index->matchesOrdering($ordering)) {
                    return $index;
                }
            }
        }

        foreach ($conditions as $condition) {
            if (!$this->conditionIndexServable($tableSchema, $condition)) {
                continue;
            }

            foreach ($tableSchema->indexes as $index) {
                if ($index->isService) {
                    continue;
                }

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
     * Whether an index may serve this condition. Equality (EQ/IN) always
     * qualifies — the strict `===` post-filter corrects any key-space
     * nuance. Range operators qualify only when the column's value order
     * provably matches the index key order: known typed columns in Binary
     * mode; string columns are excluded in Locale mode (collator vs byte
     * order) and passthrough columns always (their cross-type comparator
     * order differs from the key tag order).
     */
    private function conditionIndexServable(
        TableSchema $tableSchema,
        FilterCondition $condition,
    ): bool {
        if (
            $condition->not
            || $condition->operator === FilterOperatorEnum::LIKE
        ) {
            return false;
        }

        if (
            $condition->operator === FilterOperatorEnum::EQ
            || $condition->operator === FilterOperatorEnum::IN
        ) {
            return true;
        }

        if (!$this->rangeIndexableColumn($tableSchema, $condition->field)) {
            return false;
        }

        return $this->comparisonMode !== ComparisonMode::Locale
            || !$this->isStringColumn($tableSchema, $condition->field);
    }

    /**
     * Ordering may ride an index only when every ordered column's value
     * order matches the key order — same rule as range conditions.
     *
     * @param array<int,OrderBy> $ordering
     */
    private function orderingIndexable(
        TableSchema $tableSchema,
        array $ordering,
    ): bool {
        foreach ($ordering as $order) {
            if (!$this->rangeIndexableColumn($tableSchema, $order->field)) {
                return false;
            }

            if (
                $this->comparisonMode === ComparisonMode::Locale
                && $this->isStringColumn($tableSchema, $order->field)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * A column whose engine value order provably matches the v2 key
     * order: the known scalar types and the temporal types (stored as
     * canonical strings whose strcmp order is chronological). The
     * unknown-type (passthrough) branch is defense in depth only — the
     * schema boundary rejects unknown column types, so it is unreachable
     * through any supported path — but stays: mixed scalars have a
     * comparator order that differs from the key tag order, and ranges
     * or ordering over them must never trust an index.
     */
    private function rangeIndexableColumn(
        TableSchema $tableSchema,
        string $field,
    ): bool {
        $type = $tableSchema->columns[$field] ?? null;

        if ($type === null) {
            return false;
        }

        $info = ColumnTypeInfo::parse($type);

        if ($info->temporalKind() !== null) {
            return true;
        }

        return match ($info->base) {
            ColumnTypes::STRING,
            ColumnTypes::INT,
            ColumnTypes::FLOAT,
            ColumnTypes::BOOL,
            ColumnTypes::YEAR,
            ColumnTypes::MONTH,
            ColumnTypes::DAY => true,
            default          => false,
        };
    }

    private function isStringColumn(
        TableSchema $tableSchema,
        string $field,
    ): bool {
        $type = $tableSchema->columns[$field] ?? null;

        if ($type === null) {
            return false;
        }

        return ColumnTypeInfo::parse($type)->base === ColumnTypes::STRING;
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
     * A null incoming key (any constraint field null or missing) always
     * passes — SQL semantics; stored records with a null key are skipped
     * for the same reason, so only two non-null equal keys conflict.
     *
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

        if ($incomingKey === null) {
            return;
        }

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
     *  - a PRESENT key keeps its value verbatim (including a present
     *    null — a violation the validator must keep seeing);
     *  - a schema column ABSENT from the input is back-filled: null for a
     *    nullable column, the shared ColumnDefaults value for a
     *    non-nullable one — never a blind null that typed reads and the
     *    present_null check would reject. A missing non-nullable column
     *    with no safe default (temporal) cannot be invented and fails
     *    loudly before anything is written.
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

        foreach ($tableSchema->columns as $column => $type) {
            if (\array_key_exists($column, $record)) {
                $normalized[$column] = $record[$column];

                continue;
            }

            $info = ColumnTypeInfo::parse($type);

            if ($info->nullable) {
                $normalized[$column] = null;

                continue;
            }

            if (!ColumnDefaults::hasSafeDefault($type)) {
                throw StorageException::invalidRecord(
                    $tableSchema->name,
                    'column "' . $column . '" of type ' . $type
                        . ' is missing and has no safe default',
                );
            }

            $normalized[$column] = ColumnDefaults::forType($type);
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
            if (!$condition->matches($record, $this->comparisonMode)) {
                return false;
            }
        }

        return true;
    }

    private function compareValues(
        bool | float | int | string | null $a,
        bool | float | int | string | null $b,
    ): int {
        return ValueComparator::compare($a, $b, $this->comparisonMode);
    }

    /**
     * Builds the namespaced, version-tagged cache key of a table:
     * "jdp:<format>:<db-hash>:<table>:<tag>". The database hash isolates
     * databases sharing one backend pool; the tag binds the entry to one
     * committed state of the table, so a foreign or cross-process write
     * (which changes lineCount/byteSize) makes every stale entry
     * unreachable instead of served.
     */
    private function cacheKey(string $tableName, string $tag): string
    {
        return $this->cacheNs . $tableName . ':' . $tag;
    }

    /**
     * Version tag of the table's CURRENT state:
     * "<meta lineCount>-<physical file size>-<inode>". The size and
     * inode come from a stat of the data file, not from meta.json — a
     * foreign append straight into the file (no provider, no meta
     * commit) must move the tag too. The inode kills the A-B-A class:
     * every full rewrite lands on a fresh tmp+rename inode, so a
     * delete+insert of the same byte length, a truncate+reimport or a
     * same-size value update — through ANY process — can never
     * reproduce an earlier tag and resurrect a warm entry of the old
     * state. The lineCount comes from meta.json read on every call
     * (cross-process freshness). A missing meta entry or data file
     * yields a component no writer ever commits, so such keys never
     * collide with real ones.
     *
     * The residual blind spot (documented, accepted): a FOREIGN in-place
     * edit of the file that keeps its byte size (same-length value swap
     * without a rename) moves nothing — warm entries keep serving until
     * TTL/eviction; invalidateCache() is the manual escape hatch. Every
     * write the provider itself performs moves the tag.
     */
    private function tableVersionTag(string $tableName): string
    {
        try {
            $lineCount = (string)$this->meta->getLineCount($tableName);
        } catch (StorageException) {
            $lineCount = 'nometa';
        }

        try {
            $stat = $this->ndjson->fileStat(
                $tableName,
                $tableName . '.ndjson',
            );
            $fileState = $stat['size'] . '-' . $stat['ino'];
        } catch (StorageException) {
            $fileState = 'nofile';
        }

        return $lineCount . '-' . $fileState;
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
                $this->values,
                $this->logger,
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
                $this->values,
            );
        }

        return $this->repairer;
    }

    /**
     * The FK cascade engine, wired to the provider's private consistency
     * helpers through closures (they need the provider's self-healing and
     * cache-key logic without widening its public surface).
     */
    private function fkEngine(): FkEngine
    {
        if ($this->fkEngine === null) {
            $this->fkEngine = new FkEngine(
                $this->schema,
                $this->meta,
                $this->ndjson,
                $this->indexManager,
                $this->values,
                fn (TableSchema $t)   => $this->ensureTableConsistent($t),
                fn (string $t): array => $this->readAllForWrite($t),
                fn (string $t)        => $this->invalidateCache($t),
                function (
                    string $t,
                    int $lineCount,
                    array $records,
                ): void {
                    $this->publishCacheEntry($t, $lineCount, $records);
                },
            );
        }

        return $this->fkEngine;
    }

    private function backupService(): Backup
    {
        if ($this->backup === null) {
            $this->backup = new Backup(
                $this->dbPath,
                $this->schema,
                $this->json,
                $this->ndjson,
                $this->meta,
                $this->locks,
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
                $this->values,
                $this->locks,
                $this->logger,
            );
        }

        return $this->restore;
    }
}
