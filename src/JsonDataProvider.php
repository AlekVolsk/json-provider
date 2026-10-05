<?php

declare(strict_types=1);

namespace AV\JsonProvider;

use AV\JsonProvider\Cache\CacheInterface;
use AV\JsonProvider\Cache\NullCache;
use AV\JsonProvider\Exception\JsonProviderDataException;
use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Exception\JsonProviderLockException;
use AV\JsonProvider\Exception\JsonProviderMappingException;
use AV\JsonProvider\Exception\JsonProviderQueryException;
use AV\JsonProvider\Exception\JsonProviderRelationException;
use AV\JsonProvider\Exception\JsonProviderSchemaException;
use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\JsonProviderTableException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Exception\Locale\LocaleInterface;
use AV\JsonProvider\Index\IndexEntryList;
use AV\JsonProvider\Index\IndexFileRegion;
use AV\JsonProvider\Index\IndexKey;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Index\IndexSnapshot;
use AV\JsonProvider\Mapping\DtoMap;
use AV\JsonProvider\Mapping\DtoMapper;
use AV\JsonProvider\Mapping\DtoRegistry;
use AV\JsonProvider\Query\ComparisonModeEnum;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Query\PartialSort;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Query\ValueComparator;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Relations\FkEngine;
use AV\JsonProvider\Schema\ColumnDefaults;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\FkBackingPolicyEnum;
use AV\JsonProvider\Schema\IdentifierRules;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Services\Backup\Backup;
use AV\JsonProvider\Services\Backup\Restore;
use AV\JsonProvider\Services\Format\FreshnessEnum;
use AV\JsonProvider\Services\Format\MigrationReport;
use AV\JsonProvider\Services\Format\StorageStatus;
use AV\JsonProvider\Services\Format\TableFreshness;
use AV\JsonProvider\Services\Integrity\IntegrityRepairer;
use AV\JsonProvider\Services\Integrity\IntegrityReport;
use AV\JsonProvider\Services\Integrity\IntegrityValidator;
use AV\JsonProvider\Storage\BrokenRecordPolicyEnum;
use AV\JsonProvider\Storage\DerivedFiles;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\StorageManifest;
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
    private const string CONTEXT_ORDER_BY = 'orderBy';
    private const string CONTEXT_DISTINCT = 'distinct';

    /**
     * How many times the result must exceed the kept prefix before a bounded
     * selection is worth it instead of a full sort. Below this the native
     * usort wins; the value is where the two met in the sort benchmarks.
     */
    private const int PARTIAL_SORT_MARGIN = 4;

    /**
     * Data lines are read through their offsets while at most one line in
     * OFFSET_READ_SHARE is wanted.
     */
    private const int OFFSET_READ_SHARE = 8;

    /**
     * An index lookup that finds more than this share of the table's lines
     * is dropped for a full scan: past it, reading the found lines and
     * checking them against the index costs more than reading every line.
     */
    private const float INDEX_MAX_SHARE = 0.8;

    private const string SCHEMA_FILE = 'information_schema.json';

    private const string LEGACY_FORMAT_NOTICE = 'database "%s" is stored in '
        . 'storage format generation 1 (written by json-provider 1.0): it '
        . 'works as before, without the protections of generation %d; '
        . 'migrate it with migrateStorage() to switch them on';

    private const string READ_ONLY_NOTICE = 'database is open read-only: it '
        . 'uses storage features this engine cannot write safely: %s';

    private const string UNSTAMPED_NOTICE = 'table "%s" carries no '
        . 'generation-2 stamp (an older engine created or restored it): it '
        . 'is trusted by size alone, as under 1.0, until a full rewrite, a '
        . 'repair or a storage migration stamps it';

    private const string STALE_NOTICE = 'table "%s" was replaced after its '
        . 'last stamped commit (an older engine rewrote it, or a rewrite was '
        . 'interrupted): its indexes are not trusted until the next write, '
        . 'repair or storage migration re-verifies them';

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
    private readonly TableFreshness $freshness;
    private readonly DerivedFiles $derived;

    /**
     * True while a storage migration runs: it rewrites or re-stamps the
     * very tables the per-table notices would report.
     */
    private bool $migrating = false;

    /**
     * Tables already reported as unstamped or stale by this instance:
     * each is reported once per process (once per request under FPM).
     *
     * @var array<string,true>
     */
    private array $freshnessNoted = [];
    private IntegrityValidator | null $validator = null;
    private IntegrityRepairer | null $repairer = null;
    private FkEngine | null $fkEngine = null;
    private Backup | null $backup = null;
    private Restore | null $restore = null;
    private readonly string $dbPath;
    private readonly string $cacheNs;
    private readonly LoggerInterface | null $logger;
    private ComparisonModeEnum $comparisonMode = ComparisonModeEnum::Binary;
    private BrokenRecordPolicyEnum $brokenRecordPolicy
        = BrokenRecordPolicyEnum::Drop;
    private FkBackingPolicyEnum $fkBackingPolicy
        = FkBackingPolicyEnum::SingleColumn;

    /**
     * Entries an index may hold past its sorted head before an append
     * rewrites it sorted: every lookup reads the tail whole, a merge
     * rewrites the whole index file.
     */
    private int $indexTailLimit = 1024;

    /**
     * Under BrokenRecordPolicyEnum::Refuse, the lines the latest write-path
     * read of each table skipped (a torn tail excluded): the number of
     * such lines and the physical number of the first. A rewrite is
     * always based on a read made in the same critical section, so the
     * entry describes exactly the records about to be written.
     *
     * @var array<string,array{line:int,count:int}>
     */
    private array $skippedLines = [];

    /**
     * roCompat features of the storage format this engine does not know;
     * non-empty means every write is refused.
     *
     * @var list<string>
     */
    private readonly array $readOnlyFeatures;

    private function __construct(
        string $dbPath,
        CacheInterface | null $cache = null,
        LoggerInterface | null $logger = null,
    ) {
        $this->logger = $logger;

        if ($logger !== null) {
            JsonProviderException::setLogger($logger);
        }

        $this->dbPath = $dbPath;
        $real = realpath($dbPath);
        $this->cacheNs = 'jdp:' . self::CACHE_FORMAT_VERSION . ':'
            . substr(sha1($real === false ? $dbPath : $real), 0, 16) . ':';
        $this->locks = new TableLockManager($dbPath);
        $this->ndjson = new NdjsonStorage($dbPath, $this->locks);
        $this->json = new JsonStorage($dbPath, $this->locks);
        $this->schema = new SchemaRegistry($this->json);
        $this->cache = $cache ?? new NullCache();
        $this->derived = new DerivedFiles($dbPath);
        $this->indexManager = new IndexManager($this->ndjson, $this->derived);
        $this->meta = new MetaRegistry($this->json);
        $this->values = new ValueValidator();
        $this->dtoRegistry = new DtoRegistry();
        $this->dtoMapper = new DtoMapper();
        $this->freshness = new TableFreshness(
            $this->meta,
            $this->ndjson,
            $this->indexManager,
            $this->values,
        );
        $this->readOnlyFeatures = $this->openStorageFormat();
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
            ->exists(self::SCHEMA_FILE);
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
            self::SCHEMA_FILE,
            ['tables' => new \stdClass(), 'relations' => []],
        );
        $bootstrap->createObjectFile('meta.json');
        StorageManifest::current()->write($dbPath);

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
     * Drops the locale: messages render in the vocabulary they were thrown
     * with.
     */
    public function resetLocale(): self
    {
        JsonProviderException::resetLocale();

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
    public function setComparisonMode(ComparisonModeEnum $mode): self
    {
        $this->comparisonMode = $mode;

        return $this;
    }

    /**
     * Sets what a write that rewrites a table does with a data-file line
     * that is not a record on this instance. Drop (default) loses the
     * line; Refuse fails the write with BrokenRecordBlocksRewrite before
     * the disk is touched (see BrokenRecordPolicyEnum).
     */
    public function setBrokenRecordPolicy(BrokenRecordPolicyEnum $policy): self
    {
        $this->brokenRecordPolicy = $policy;

        return $this;
    }

    /**
     * Sets which user index addRelation(), dropIndex() and repair() may
     * pick as the backing index of a probing relation on this instance.
     * SingleColumn (default) takes only an index on exactly the FK column;
     * LeadingColumn also takes one led by it (see FkBackingPolicyEnum for
     * what engines before 1.2 make of such a relation).
     */
    public function setFkBackingPolicy(FkBackingPolicyEnum $policy): self
    {
        $this->fkBackingPolicy = $policy;

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
     * Unbinds the DTO of each named table, so another class can be bound
     * to it (registerDto refuses to replace a live binding). The binding
     * is process-local state, not schema: nothing on disk changes and the
     * *ByArray surface is unaffected.
     *
     * Loud by name: the argument is a TABLE name, so a class-string
     * (backslashes) fails INVALID_TABLE_NAME instead of silently doing
     * nothing, and a table with no DTO bound fails DTO_NOT_REGISTERED
     * rather than pretending a typo was a no-op.
     *
     * A JsonTable handle obtained earlier keeps the map it was built
     * with — take a fresh $db->table(...) after rebinding.
     */
    public function unregisterDto(string ...$tables): self
    {
        foreach ($tables as $table) {
            IdentifierRules::assertTableName($table);

            if ($this->dtoRegistry->forTable($table) === null) {
                throw new JsonProviderMappingException(
                    JsonProviderErrorEn::DtoNotRegistered,
                    $table,
                );
            }

            $this->dtoRegistry->unregister($table);
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
        $this->assertWritable();

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
        $this->assertWritable();

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
                $this->derived->dropTable($tableName);
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
        $this->assertWritable();

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
     * Guards (all throw a provider exception, nothing is written):
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
        $this->assertWritable();

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
                $this->ensureTableConsistent($current);
                $records = $this->readAllForWrite($target->name);
                $this->assertRewritable($target->name);

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
        $this->assertWritable();

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
                $this->checkUniqueOnInsert($tableSchema, $record);

                $probe = $this->normalizeRecord(
                    $tableSchema,
                    $record + ['id' => 0],
                );

                $encoded = json_encode($probe, JSON_PRESERVE_ZERO_FRACTION);

                if ($encoded === false) {
                    throw new JsonProviderDataException(
                        JsonProviderErrorEn::RecordJsonEncodeFailed,
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
                $this->mergeIndexTails($tableSchema, $lineNumber + 1);
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
        $this->assertWritable();

        return $this->withMutationLocks(
            $tableName,
            false,
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
                $matches = $this->conditionFilter($conditions);

                foreach ($records as $index => $existing) {
                    if ($matches($existing)) {
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
        $this->assertWritable();

        return $this->withMutationLocks(
            $tableName,
            true,
            function (TableSchema $tableSchema) use ($conditions): int {
                $tableName = $tableSchema->name;
                $conditions = $this->values->encodeConditions(
                    $tableSchema,
                    $conditions,
                );
                $this->ensureTableConsistent($tableSchema);
                $records = $this->readAllForWrite($tableName);

                $deleteIndexes = [];
                $matches = $this->conditionFilter($conditions);

                foreach ($records as $index => $record) {
                    if ($matches($record)) {
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
     *  - unknown columns — ReorderColumnsUnknown;
     *  - duplicate columns — ReorderColumnsDuplicate;
     *  - id missing in $newOrder — id is prepended;
     *  - id present but not first — id is moved to position 0;
     *  - after the id-normalization the list must contain every existing
     *    column; otherwise ReorderColumnsIncomplete.
     *
     * Order of disk operations: schema first, then NDJSON data. Rationale:
     * if the data write fails after the schema write, lazy normalization on
     * the next mutation will re-emit each record in the new column order.
     * If the schema write fails first, the operation is invisible.
     *
     * Indexes are rebuilt by the standard full rewrite (writeAll): their
     * logical content stays the same — column order inside a record does
     * not affect index keys — and the pass re-stamps the current index
     * format along the way.
     *
     * @param array<int,string> $newOrder
     */
    public function reorderColumns(string $tableName, array $newOrder): void
    {
        $this->assertWritable();

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

                $this->ensureTableConsistent($tableSchema);
                $records = $this->readAllForWrite($tableName);
                $this->assertRewritable($tableName);

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
        $this->assertWritable();

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
     * Replaces the whole content of a table with the given records in one
     * rewrite — the bulk counterpart of insert(), for seeding, imports and
     * restores. Returns the number of records written.
     *
     * Every record goes through the ordinary normalization and validation
     * used by insert(), unique constraints are checked across the whole
     * batch, then the data file is atomically replaced, indexes
     * are rebuilt, meta (lineCount/byteSize) is committed and the cache is
     * republished — so the table ends up in exactly the state a sequence of
     * insert() calls would leave, at a fraction of the cost. The
     * auto-increment counter is raised to the largest supplied id and never
     * lowered, so the next insert() continues after the imported rows and
     * ids of replaced rows are not reissued.
     *
     * Ids must be supplied and unique within the batch: this is a load of
     * known records, not a sequence of appends. Every record is validated
     * before anything is written, so a bad row aborts the import with the
     * table untouched rather than half-replaced.
     *
     * Foreign keys are NOT enforced (symmetric with truncate and dropTable):
     * import the parent side first, or run validate() afterwards if the
     * source is untrusted.
     *
     * Runs under the database + table EX locks.
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    public function importRecords(string $tableName, array $records): int
    {
        $this->assertWritable();

        // @var int<0, max>
        return $this->locks->withLocks(
            [$tableName => 'ex'],
            'ex',
            function () use ($tableName, $records): int {
                $this->schema->reload();
                $tableSchema = $this->schema->getTable($tableName);

                /*
                 * Before the rewrite, while the old tag still resolves —
                 * afterwards it would tear down the entry writeAll just
                 * published (same ordering as truncate).
                 */
                $prepared = [];
                $watermark = 0;
                $seen = [];

                foreach ($records as $record) {
                    $id = $record[PrimaryKey::FIELD] ?? null;

                    if (!\is_int($id) || $id < 1) {
                        throw new JsonProviderDataException(
                            JsonProviderErrorEn::RecordImportIdInvalid,
                            $tableSchema->name,
                            get_debug_type($id),
                        );
                    }

                    if (isset($seen[$id])) {
                        throw new JsonProviderDataException(
                            JsonProviderErrorEn::RecordImportIdDuplicate,
                            $tableSchema->name,
                            (string)$id,
                        );
                    }

                    $seen[$id] = true;

                    if ($id > $watermark) {
                        $watermark = $id;
                    }

                    $prepared[] = [PrimaryKey::FIELD => $id]
                        + $this->values->encodeForWrite(
                            $tableSchema,
                            $record,
                            true,
                        );
                }

                foreach ($tableSchema->uniqueConstraints as $constraint) {
                    $this->assertNoUniqueDuplicates(
                        $tableName,
                        $constraint,
                        $prepared,
                    );
                }

                /*
                 * Nothing is touched until every record has passed
                 * validation: a bad row in the middle of the batch leaves the
                 * table as it was, not half-replaced.
                 */
                $this->invalidateCache($tableName);
                $this->ensureTableConsistent($tableSchema);
                $this->writeAll($tableName, $tableSchema, $prepared);
                $this->meta->setLastInsertedId(
                    $tableName,
                    max($watermark, $this->meta->getLastInsertedId($tableName)),
                );

                return \count($prepared);
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
        $this->assertWritable();

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

                $this->ensureTableConsistent($tableSchema);
                $records = $this->readAllForWrite($tableName);
                $this->assertRewritable($tableName);

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

                $this->createMissingIndexFiles($newSchema);
                $this->writeAll($tableName, $newSchema, $renamed);

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
        $this->assertWritable();

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
     * Throws ColumnNotFound if the column is not declared in
     * the table. Schema-only meta-operation — data and indexes are untouched.
     */
    public function setColumnComment(
        string $tableName,
        string $column,
        string | null $comment,
    ): void {
        $this->assertWritable();

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
     * Sets the column-comment map. By default replaces the whole map; with
     * $merge=true merges the given entries on top of the existing ones. Every
     * key must be an existing column, otherwise
     * ColumnNotFound.
     * An empty-string value clears that column. Schema-only meta-operation.
     *
     * @param array<string,string> $comments column name => description
     */
    public function setColumnComments(
        string $tableName,
        array $comments,
        bool $merge = false,
    ): void {
        $this->assertWritable();

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
     * Throws IndexNotFound if the index name does not
     * exist in the table schema.
     */
    public function rebuildIndex(string $tableName, string $indexName): void
    {
        $this->assertWritable();

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
        $this->assertWritable();

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
     * (INDEX_UNKNOWN_COLUMN).
     *
     * Order: the file is provisioned and built first, the schema published
     * last — a crash in between leaves an undeclared file that validate()
     * reports as orphan and repair() removes. On a legacy-format table
     * every existing index is rebuilt with the current encoder and the
     * format stamped, so the table never mixes key formats.
     */
    public function addIndex(string $tableName, IndexSchema $index): void
    {
        $this->assertWritable();

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
        $this->assertWritable();

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
     * UNIQUE_CONSTRAINT_UNKNOWN_COLUMN. Schema-only mutation — constraints
     * have no files.
     */
    public function addUniqueConstraint(
        string $tableName,
        UniqueConstraint $constraint,
    ): void {
        $this->assertWritable();

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

                $this->assertNoUniqueDuplicates(
                    $tableName,
                    $constraint,
                    $this->readAllForWrite($tableName),
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
        $this->assertWritable();

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

    /**
     * Declares a relation (DDL, under the database EX + child table EX
     * locks): validates it against the fresh schema (tables and columns
     * exist, base types match, the referenced column is unique, onUpdate
     * is not declared on the PK, SET NULL lands on a nullable column),
     * rejects a canonical duplicate, provisions the FK backing index for
     * probing (cascade/restrict) actions, and persists. The relation is
     * active immediately — no restart or migration step.
     *
     * Backing resolution: a USER index the backing policy accepts is
     * reused (see setFkBackingPolicy()); otherwise a service index
     * "_fk_<column>" is built
     * (file first, then the schema RMW that also records the relation, so
     * a crash in between leaves only an orphan index file for repair to
     * sweep). Any backingIndex preset on the passed descriptor is
     * ignored — the engine owns that field.
     */
    public function addRelation(RelationSchema $relation): void
    {
        $this->assertWritable();

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
        $this->assertWritable();

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
        $this->assertWritable();

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
        $this->assertWritable();

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
        $this->assertWritable();

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
     * Returns the path of the created archive — $destination as given
     * (relative stays relative), completed with the default file name or
     * extension when needed. The export runs
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
        $this->assertWritable();

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

        $this->migrateStorage();
    }

    /**
     * Reports the storage format of the database as it is on disk now:
     * its generation, this engine's generation, the manifest flags, and
     * what migrateStorage() would do — the generation steps it would run
     * and the tables it would rebuild and stamp. Reads only; takes no
     * write lock.
     */
    public function storageStatus(): StorageStatus
    {
        $manifest = StorageManifest::read($this->dbPath);
        $pendingTables = [];

        foreach ($this->schema->getTables() as $name => $tableSchema) {
            if (
                $manifest->isLegacy()
                || $this->freshness->verdict($tableSchema, true)
                !== FreshnessEnum::FRESH
                || !$this->derivedCurrent($tableSchema)
            ) {
                $pendingTables[] = $name;
            }
        }

        $pendingSteps = [];

        for (
            $generation = $manifest->generation;
            $generation < StorageManifest::GENERATION;
            $generation++
        ) {
            $pendingSteps[] = self::stepName($generation);
        }

        return new StorageStatus(
            generation: $manifest->generation,
            engineGeneration: StorageManifest::GENERATION,
            compat: $manifest->compat,
            roCompat: $manifest->roCompat,
            incompat: $manifest->incompat,
            readOnly: $manifest->unknownRoCompat() !== [],
            pendingSteps: $pendingSteps,
            pendingTables: $pendingTables,
            pendingFeatures: $manifest->isLegacy()
                ? []
                : $manifest->missingFeatures(),
        );
    }

    /**
     * Brings the database to the given storage format generation (this
     * engine's by default) and makes every table fresh: indexes rebuilt
     * from the data and stamped. Generations are climbed one step at a
     * time, each step idempotent, the manifest written as the step's last
     * act — a crash leaves the database in the previous generation and a
     * re-run finishes the job. Only upwards: a target below the database's
     * generation, or above this engine's, is refused.
     *
     * Runs under the database EX lock and EX on every table, like
     * repair(); meant for deploy time, not for live traffic. A table whose
     * data file holds lines that are not records is left unstamped and
     * listed in the report; it keeps working as under 1.0.
     */
    public function migrateStorage(
        int | null $toGeneration = null,
    ): MigrationReport {
        $this->assertWritable();
        $target = $toGeneration ?? StorageManifest::GENERATION;

        $report = $this->locks->withDatabase(
            function () use ($target): MigrationReport {
                $this->schema->reload();
                $plan = array_fill_keys(
                    array_keys($this->schema->getTables()),
                    'ex',
                );

                return $this->locks->withLocks(
                    $plan,
                    null,
                    fn (): MigrationReport => $this->migrateLocked($target),
                );
            },
        );

        foreach (array_keys($this->schema->getTables()) as $table) {
            $this->invalidateCache($table);
        }

        return $report;
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
     * The index is used only when trusted (indexTrustedLineCount: committed
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
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::InvalidLimit,
                $tableName,
                $limit,
            );
        }

        if ($offset < 0) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::InvalidOffset,
                $tableName,
                $offset,
            );
        }

        $this->assertKnownColumns($tableSchema, $ordering, $distinctFields);
        $conditions = $this->values->encodeConditions(
            $tableSchema,
            $conditions,
        );
        $keepPrefix = $limit !== null && $distinctFields === []
            ? ($limit > PHP_INT_MAX - $offset ? PHP_INT_MAX : $offset + $limit)
            : null;
        $pushPagination = $keepPrefix !== null;
        $index = $this->resolveIndex(
            $tableSchema,
            $ordering,
            $conditions,
            $pushPagination,
        );
        $paginatedByIndex = false;
        $records = null;

        if ($index !== null) {
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
             * The SH lock covers only the snapshot (openIndexSnapshot): the
             * index and the data file are opened under it and read after it
             * is released, so readers hold a table only for a moment.
             *
             * @var null|array{IndexSchema, TableSchema, IndexSnapshot} $opened
             */
            $opened = $this->locks->withLocks(
                [$tableName => 'sh'],
                null,
                function () use (
                    $tableName,
                    $conditions,
                    $ordering,
                    $pushPagination,
                ): array | null {
                    $freshSchema = $this->schema->getTable($tableName);
                    $freshIndex = $this->resolveIndex(
                        $freshSchema,
                        $ordering,
                        $conditions,
                        $pushPagination,
                    );
                    $snapshot = $freshIndex === null
                        ? null
                        : $this->openIndexSnapshot(
                            $freshIndex,
                            $freshSchema,
                            $ordering,
                        );

                    return $freshIndex === null || $snapshot === null
                        ? null
                        : [$freshIndex, $freshSchema, $snapshot];
                },
            );

            if ($opened !== null) {
                [$freshIndex, $freshSchema, $snapshot] = $opened;
                $appliedPagination = $ordering !== []
                    && $freshIndex->matchesOrdering($ordering)
                    && $this->orderingIndexable($freshSchema, $ordering)
                    && $distinctFields === [];
                $records = $this->selectViaIndex(
                    $snapshot,
                    $freshIndex,
                    $freshSchema,
                    $conditions,
                    $ordering,
                    $appliedPagination ? $limit : null,
                    $appliedPagination ? $offset : 0,
                );
                $paginatedByIndex = $records !== null && $appliedPagination;
            }
        }

        if ($records === null) {
            /*
             * Only the first offset+limit records survive the slice below, so
             * the sort may stop there — but not when dedup still has to run,
             * since it consumes rows the prefix would already have dropped.
             */
            $records = $this->selectFullScan(
                $tableName,
                $conditions,
                $ordering,
                $keepPrefix,
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
     * An unconditional count needs no row data, so it is answered from the
     * meta counter when that counter is provably current — see
     * countFromMeta(). A count with conditions is answered from the data
     * cache when it holds the table's current version, otherwise through
     * an index that serves the conditions (countViaIndex()); everything
     * else reads and filters the rows.
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

        if ($conditions === []) {
            $fromMeta = $this->countFromMeta($tableSchema);

            if ($fromMeta !== null) {
                return $fromMeta;
            }
        }

        $cached = $this->cache->get($this->cacheKey(
            $tableName,
            $this->tableVersionTag($tableName),
        ));

        if ($cached !== null) {
            return $conditions === []
                ? \count($cached)
                : \count(array_filter(
                    $cached,
                    $this->conditionFilter($conditions),
                ));
        }

        if ($conditions !== []) {
            $viaIndex = $this->countViaIndex($tableName, $conditions);

            if ($viaIndex !== null) {
                return $viaIndex;
            }
        }

        return $this->countScan($tableSchema, $conditions);
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
     * Counts the matching rows of a full read without holding them: each
     * record is checked and dropped, so the memory a count takes does not
     * grow with the table. The data cache is not filled.
     *
     * @param array<int,FilterCondition> $conditions
     */
    private function countScan(
        TableSchema $tableSchema,
        array $conditions,
    ): int {
        return iterator_count($this->scanRecords($tableSchema, $conditions));
    }

    /**
     * The matching records of a full read, collected from the stream
     * without an intermediate copy of the table — the full scan when no
     * data cache is configured, so there is nothing to fill.
     *
     * @param array<int,FilterCondition> $conditions
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function scanMatching(
        TableSchema $tableSchema,
        array $conditions,
    ): array {
        $records = [];

        foreach ($this->scanRecords($tableSchema, $conditions) as $record) {
            $records[] = $record;
        }

        return $records;
    }

    /**
     * The records of a full read matching the conditions, one at a time:
     * widened and filtered as they stream; the stored column names are
     * checked on the first, as a whole read checks them.
     *
     * @param array<int,FilterCondition> $conditions
     *
     * @return \Generator<int,array<string,null|scalar>>
     */
    private function scanRecords(
        TableSchema $tableSchema,
        array $conditions,
    ): \Generator {
        $matches = $this->conditionFilter($conditions);
        $first = true;
        $records = $this->ndjson->records(
            $tableSchema->name,
            $tableSchema->getFileName(),
        );

        foreach ($records as $line => $record) {
            if ($first) {
                $this->assertStoredColumnNames([$record]);
                $first = false;
            }

            $record = $this->values->widenRecord($tableSchema, $record);

            if ($matches($record)) {
                yield $line => $record;
            }
        }
    }

    /**
     * Counts the rows matching the conditions through an index: the same
     * index, the same lookup (indexLineNumbers) and the same checks as a
     * select with these conditions, so the two cannot answer differently
     * — only the rows are counted as they stream instead of held. Null when
     * no index serves the conditions or the index is not trusted; the
     * caller then counts a full read. Used only when the data cache holds
     * no entry for the table's current version: a cached table is counted
     * from memory.
     *
     * @param array<int,FilterCondition> $conditions
     */
    private function countViaIndex(
        string $tableName,
        array $conditions,
    ): int | null {
        if (
            $this->resolveIndex(
                $this->schema->getTable($tableName),
                [],
                $conditions,
            ) === null
        ) {
            return null;
        }

        /** @var null|array{IndexSchema, TableSchema, IndexSnapshot} $opened */
        $opened = $this->locks->withLocks(
            [$tableName => 'sh'],
            null,
            function () use ($tableName, $conditions): array | null {
                $tableSchema = $this->schema->getTable($tableName);
                $index = $this->resolveIndex($tableSchema, [], $conditions);
                $snapshot = $index === null
                    ? null
                    : $this->openIndexSnapshot($index, $tableSchema, []);

                return $index === null || $snapshot === null
                    ? null
                    : [$index, $tableSchema, $snapshot];
            },
        );

        if ($opened === null) {
            return null;
        }

        [$index, $tableSchema, $snapshot] = $opened;
        $lines = $this->indexLineNumbers(
            $tableSchema,
            $index,
            $conditions,
            $snapshot->sorted,
            $snapshot->entries,
        );

        if (
            $lines === null
            || $this->tooWide($lines, $snapshot->lineCount, $snapshot->sorted)
        ) {
            return null;
        }

        return iterator_count($this->matchingRecords(
            $snapshot,
            $tableSchema,
            $index,
            $conditions,
            $lines,
        ));
    }

    /**
     * The records matching the conditions among the data lines an index
     * lookup found ($lines, in file order) — or among all lines when the
     * index served no condition ($lines null) — one at a time, never held:
     * each is widened, checked against the index entry that pointed at it
     * when the lookup searched a sorted head (RecordVerifier), and
     * filtered. A found line that yields no record breaks the index
     * (INDEX_UNRELIABLE).
     *
     * @param array<int,FilterCondition> $conditions
     * @param null|array<int,int>        $lines
     *
     * @return \Generator<int,array<string,null|scalar>>
     */
    private function matchingRecords(
        IndexSnapshot $snapshot,
        TableSchema $tableSchema,
        IndexSchema $index,
        array $conditions,
        array | null $lines,
    ): \Generator {
        $sorted = $snapshot->sorted;
        $matches = $this->conditionFilter($conditions);
        $verify = $sorted === null || $lines === null
            ? null
            : $this->indexManager->recordVerifier(
                $tableSchema->name,
                $sorted,
                $index,
            );
        $read = 0;
        $records = $lines === null
            ? NdjsonStorage::recordsFrom($snapshot->data, $snapshot->dataSize)
            : $this->dataRecords($snapshot, $tableSchema, $lines);

        foreach ($records as $line => $record) {
            $record = $this->values->widenRecord($tableSchema, $record);
            $read++;
            $verify?->check($line, $record);

            if ($matches($record)) {
                yield $line => $record;
            }
        }

        if ($verify !== null && $read !== \count($lines ?? [])) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::IndexLinesMissing,
                $index->name,
                $tableSchema->name,
            );
        }
    }

    /**
     * readDataLines() as a stream keyed by line number.
     *
     * @param array<int,int> $lineNumbers in file order
     *
     * @return \Generator<int,array<string,null|scalar>>
     */
    private function dataRecords(
        IndexSnapshot $snapshot,
        TableSchema $tableSchema,
        array $lineNumbers,
    ): \Generator {
        $offsets = $this->offsetsOf($snapshot, $tableSchema, $lineNumbers);

        return $offsets === null
            ? NdjsonStorage::recordsFrom(
                $snapshot->data,
                $snapshot->dataSize,
                $lineNumbers,
            )
            : NdjsonStorage::recordsAtFrom($snapshot->data, $offsets);
    }

    /**
     * The offsets of the wanted data lines in the snapshot's data file,
     * or null when they are better read in one pass — more than one line
     * in OFFSET_READ_SHARE is wanted — or the offsets file does not
     * describe that file.
     *
     * @param array<int,int> $lineNumbers
     *
     * @return null|array<int,int>
     */
    private function offsetsOf(
        IndexSnapshot $snapshot,
        TableSchema $tableSchema,
        array $lineNumbers,
    ): array | null {
        $wide = \count($lineNumbers) * self::OFFSET_READ_SHARE
            > $snapshot->lineCount;

        return $wide
            ? null
            : $this->derived->lineOffsets(
                $tableSchema->name,
                $snapshot->data,
                $snapshot->lineCount,
                $lineNumbers,
            );
    }

    /**
     * The committed row count, or null when it cannot be trusted without
     * reading the table.
     *
     * The gate is the one ensureTableConsistent() runs before a write
     * (TableFreshness): meta byteSize against the actual file size, and in a
     * generation-2 database the inode against the stamp. lineCount and
     * byteSize are committed together (commitAppend/commitRewrite), so a
     * trusted table means the counter describes exactly this file. Anything
     * else — crashed append, foreign write, pre-byteSize meta, a replaced
     * file — yields null and the caller falls back to counting the rows.
     *
     * The blind spot is inherited from that gate: an unstamped table, and
     * any table of a generation-1 database, is trusted by size alone, so a
     * foreign rewrite landing on the same byte length is invisible here,
     * exactly as it is to the write path.
     */
    private function countFromMeta(TableSchema $tableSchema): int | null
    {
        return $this->freshness->trustedLineCount($tableSchema);
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
     * Runs a mutation body under the FK-aware lock plan of the table — the
     * plan of a delete ($delete) or of an update, which differ in the
     * relation actions they follow — closing the plan-staleness window:
     * the plan is computed from the pre-lock schema snapshot, and a
     * relation added by a concurrent
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
    private function withMutationLocks(
        string $tableName,
        bool $delete,
        callable $body,
    ) {
        $attempts = 0;

        while (true) {
            $plan = $this->mutationLockPlan(
                $this->schema->getTable($tableName),
                $delete,
            );

            $outcome = $this->locks->withLocks(
                $plan,
                'sh',
                function () use ($tableName, $delete, $body): array | null {
                    $tableSchema = $this->schema->getTable($tableName);
                    $freshPlan = $this->mutationLockPlan($tableSchema, $delete);

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
                throw new JsonProviderLockException(
                    JsonProviderErrorEn::LockPlanStale,
                    $tableName,
                );
            }
        }
    }

    /**
     * The FK engine's lock plan of a delete ($delete) or an update.
     *
     * @return array<string,string>
     */
    private function mutationLockPlan(
        TableSchema $tableSchema,
        bool $delete,
    ): array {
        return $delete
            ? $this->fkEngine()->deleteLockPlan($tableSchema)
            : $this->fkEngine()->updateLockPlan($tableSchema);
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

        if ($this->fkBackingPolicy === FkBackingPolicyEnum::LeadingColumn) {
            $user = $this->fkBackingPolicy->userBacking(
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
     * a USER index the backing policy accepts is reused
     * (FkBackingPolicyEnum::userBacking); otherwise the service descriptor
     * "_fk_<column>" is returned (which may itself already be declared by
     * another relation on the same column and is then shared).
     */
    private function resolveBackingIndex(
        string $childTable,
        string $column,
    ): IndexSchema {
        $user = $this->fkBackingPolicy->userBacking(
            $this->schema->getTable($childTable)->indexes,
            $column,
        );

        if ($user !== null) {
            return $user;
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
        $file = $tableSchema->getFileName();

        if ($this->brokenRecordPolicy === BrokenRecordPolicyEnum::Drop) {
            $records = $this->ndjson->read($tableName, $file);
        } else {
            $raw = $this->ndjson->readRawLines($tableName, $file);
            $records = $raw['records'];
            $this->noteSkippedLines(
                $tableName,
                $this->ndjson->brokenBeyondTornTail(
                    $tableName,
                    $file,
                    $raw['broken'],
                ),
            );
        }

        $this->assertStoredColumnNames($records);

        return $this->values->widenFloats($tableSchema, $records);
    }

    /**
     * Records which lines the latest write-path read of the table skipped.
     *
     * @param array<int,array{line:int,raw:string}> $broken
     */
    private function noteSkippedLines(string $tableName, array $broken): void
    {
        if ($broken === []) {
            unset($this->skippedLines[$tableName]);

            return;
        }

        $this->skippedLines[$tableName] = [
            'line'  => $broken[0]['line'],
            'count' => \count($broken),
        ];
    }

    /**
     * Under BrokenRecordPolicyEnum::Refuse, fails a rewrite whose base read
     * skipped lines that are not records: writing that base would lose
     * them. Called after the read and before anything is written.
     */
    private function assertRewritable(string $tableName): void
    {
        $skipped = $this->skippedLines[$tableName] ?? null;

        if (
            $skipped === null
            || $this->brokenRecordPolicy !== BrokenRecordPolicyEnum::Refuse
        ) {
            return;
        }

        throw new JsonProviderDataException(
            JsonProviderErrorEn::BrokenRecordBlocksRewrite,
            $tableName,
            $skipped['count'],
            $skipped['line'],
        );
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
     * Compares two records field by field under the ordering rules.
     *
     * @param array<string,null|scalar> $a
     * @param array<string,null|scalar> $b
     * @param array<int,OrderBy>        $ordering
     */
    private function compareRecords(
        array $a,
        array $b,
        array $ordering,
    ): int {
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
    }

    /**
     * Sorts records in place by the ordering rules (stable — usort in
     * PHP 8+).
     *
     * @param array<int,array<string,null|scalar>> $records
     * @param array<int,OrderBy>                   $ordering
     */
    private function sortByOrdering(
        array &$records,
        array $ordering,
        int | null $keep = null,
    ): void {
        /*
         * With a limit small against the result, only the first $keep records
         * are ever returned, so a bounded selection replaces the full sort
         * (n log k against n log n). The margin keeps it out of the way when
         * the limit approaches the row count: there the engine-level usort,
         * running in C, beats a heap driven from PHP.
         */
        if (
            $keep !== null
            && $keep > 0
            && $keep * self::PARTIAL_SORT_MARGIN <= \count($records)
        ) {
            $records = PartialSort::top(
                $records,
                fn (array $a, array $b): int => $this->compareRecords(
                    $a,
                    $b,
                    $ordering,
                ),
                $keep,
            );

            return;
        }

        usort(
            $records,
            fn (array $a, array $b): int => $this->compareRecords(
                $a,
                $b,
                $ordering,
            ),
        );
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
                throw new JsonProviderTableException(
                    JsonProviderErrorEn::RenameIncomplete,
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

        $committed = null;

        try {
            $committed = $this->meta->getCommittedFile($tableSchema->name);
        } catch (JsonProviderException $e) {
            /*
             * Both a missing and a corrupt entry self-heal the same way:
             * the counters are fully derivable from the data, so the
             * entry is re-initialized and the rewrite below re-commits
             * the true lineCount/byteSize (with the id watermark
             * restored first).
             */
            $corrupt = $e->error === JsonProviderErrorEn::MetaEntryNotObject
                || $e->error === JsonProviderErrorEn::MetaCounterNotInt;

            if ($corrupt) {
                $this->meta->dropEntry($tableSchema->name);
            } elseif ($e->error !== JsonProviderErrorEn::MetaEntryMissing) {
                throw $e;
            }

            $this->meta->initTable($tableSchema->name);
        }

        if (
            $committed !== null
            && $this->trustedNow($tableSchema, $committed)
        ) {
            if ($committed['indexFormat'] >= 2) {
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
        $this->assertRewritable($tableSchema->name);

        if ($committed === null) {
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
            throw new JsonProviderLockException(
                JsonProviderErrorEn::WriteLockRequired,
                __METHOD__,
                $tableName,
            );
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
                TableSchema::dataFileName($tableName),
            );
        } catch (JsonProviderException) {
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
        IndexSnapshot $snapshot,
        IndexSchema $index,
        TableSchema $tableSchema,
        array $conditions,
        array $ordering,
        int | null $limit = null,
        int $offset = 0,
    ): array | null {
        return $this->selectViaIndexTrusted(
            $snapshot,
            $index,
            $tableSchema,
            $conditions,
            $ordering,
            $limit,
            $offset,
        );
    }

    /**
     * The trusted-index read pipeline behind selectViaIndex; a structurally
     * corrupt index raises loudly instead of degrading to a full scan.
     *
     * @param array<int,FilterCondition> $conditions
     * @param array<int,OrderBy>         $ordering
     *
     * @return null|array<int,array<string,null|scalar>>
     */
    private function selectViaIndexTrusted(
        IndexSnapshot $snapshot,
        IndexSchema $index,
        TableSchema $tableSchema,
        array $conditions,
        array $ordering,
        int | null $limit = null,
        int $offset = 0,
    ): array | null {
        $lineCount = $snapshot->lineCount;
        $sorted = $snapshot->sorted;
        $entries = $snapshot->entries;

        if ($this->orderedByIndex($index, $tableSchema, $ordering)) {
            $lineNumbers = array_column($entries, 'line');

            if ($conditions === []) {
                if ($offset > 0 || $limit !== null) {
                    $lineNumbers = \array_slice($lineNumbers, $offset, $limit);
                }

                $records = $this->values->widenFloats(
                    $tableSchema,
                    $this->readDataLines($snapshot, $tableSchema, $lineNumbers),
                );

                if (\count($records) !== \count($lineNumbers)) {
                    throw new JsonProviderServiceException(
                        JsonProviderErrorEn::IndexLinesMissing,
                        $index->name,
                        $tableSchema->name,
                    );
                }

                return $records;
            }

            return $this->readFilteredPaginated(
                $snapshot,
                $tableSchema,
                $lineNumbers,
                $conditions,
                $offset,
                $limit,
            );
        }

        $lineNumbers = $this->indexLineNumbers(
            $tableSchema,
            $index,
            $conditions,
            $sorted,
            $entries,
        );

        if ($this->tooWide($lineNumbers, $lineCount, $sorted)) {
            return null;
        }

        $records = [];
        $matching = $this->matchingRecords(
            $snapshot,
            $tableSchema,
            $index,
            $conditions,
            $lineNumbers,
        );

        foreach ($matching as $record) {
            $records[] = $record;
        }

        if ($ordering !== []) {
            $this->sortByOrdering($records, $ordering);
        }

        return $records;
    }

    /**
     * Whether the query is read in the order of the index: its ordering is
     * the index's, and the index order agrees with the comparison mode.
     *
     * @param array<int,OrderBy> $ordering
     */
    private function orderedByIndex(
        IndexSchema $index,
        TableSchema $tableSchema,
        array $ordering,
    ): bool {
        return $ordering !== []
            && $index->matchesOrdering($ordering)
            && $this->orderingIndexable($tableSchema, $ordering);
    }

    /**
     * What an index read needs from the table, taken under its SH lock to
     * read after the lock is released (IndexSnapshot): null when the index
     * is not trusted. A read in index order takes the index whole;
     * otherwise its sorted head is opened for searching in place, capped
     * by the share past which a full scan is cheaper (tooWide()), or the
     * index is read whole when no head is recorded.
     *
     * @param array<int,OrderBy> $ordering
     */
    private function openIndexSnapshot(
        IndexSchema $index,
        TableSchema $tableSchema,
        array $ordering,
    ): IndexSnapshot | null {
        $lineCount = $this->indexTrustedLineCount($tableSchema);

        if ($lineCount === null) {
            return null;
        }

        $sorted = $this->orderedByIndex($index, $tableSchema, $ordering)
            ? null
            : $this->indexManager->openSorted(
                $tableSchema->name,
                $index,
                $lineCount,
            );

        if ($sorted !== null) {
            $sorted[0]->limit($this->estimateBudget($lineCount));
        }

        $entries = $sorted !== null
            ? []
            : $this->indexManager->readIndexValidated(
                $tableSchema->name,
                $index,
                $lineCount,
                true,
            );

        return $entries === null
            ? null
            : $this->dataSnapshot($tableSchema, $lineCount, $sorted, $entries);
    }

    /**
     * Opens the data file for an IndexSnapshot, at its size now.
     *
     * @param null|array{IndexFileRegion, IndexEntryList} $sorted
     * @param array<int,array{key:string,line:int}>       $entries
     */
    private function dataSnapshot(
        TableSchema $tableSchema,
        int $lineCount,
        array | null $sorted,
        array $entries,
    ): IndexSnapshot {
        $data = $this->ndjson->open(
            $tableSchema->name,
            $tableSchema->getFileName(),
        );
        $stat = fstat($data);

        return new IndexSnapshot(
            $lineCount,
            $data,
            $stat === false ? 0 : $stat['size'],
            $sorted,
            $entries,
        );
    }

    /**
     * Whether an index lookup found too large a share of the table to be
     * worth reading through the index (INDEX_MAX_SHARE): its sorted head
     * stopped reading runs over budget (indexBudget()), or the lines found
     * exceed the share.
     *
     * @param null|array<int,int>                         $lines
     * @param null|array{IndexFileRegion, IndexEntryList} $sorted
     */
    private function tooWide(
        array | null $lines,
        int $lineCount,
        array | null $sorted,
    ): bool {
        return ($sorted !== null && $sorted[0]->overBudget())
            || ($lines !== null
                && \count($lines) > $this->indexBudget($lineCount));
    }

    /**
     * The cap on the entries a lookup's runs are estimated to hold before
     * it stops reading them (IndexFileRegion::limit()): the budget with a
     * tenth on top, as the estimate is rough — the lines found decide.
     */
    private function estimateBudget(int $lineCount): int
    {
        $budget = $this->indexBudget($lineCount);

        return $budget + intdiv($budget, 10);
    }

    /**
     * The most lines an index lookup may find and still be read through
     * the index (INDEX_MAX_SHARE of the table).
     */
    private function indexBudget(int $lineCount): int
    {
        return (int)floor($lineCount * self::INDEX_MAX_SHARE);
    }

    /**
     * The data lines an index narrows the conditions to, in file order: by
     * the prefix of its leading fields when the conditions fix two or more
     * of them (indexPrefix), otherwise by the first condition it can serve.
     * Null when it serves none.
     *
     * @param array<int,FilterCondition>                  $conditions
     * @param null|array{IndexFileRegion, IndexEntryList} $sorted
     * @param array<int,array{key:string,line:int}>       $entries    the
     *                                                                whole
     *                                                                index
     *                                                                when
     *                                                                $sorted
     *                                                                is null
     *
     * @return null|array<int,int>
     */
    private function indexLineNumbers(
        TableSchema $tableSchema,
        IndexSchema $index,
        array $conditions,
        array | null $sorted,
        array $entries,
    ): array | null {
        $lineNumbers = null;
        $prefix = $this->indexPrefix($tableSchema, $index, $conditions);

        if ($prefix['fields'] >= 2) {
            $lineNumbers = $sorted !== null
                ? $this->indexManager->searchSortedPrefix(
                    $tableSchema->name,
                    $sorted,
                    $index,
                    $prefix['values'],
                    $prefix['range'],
                )
                : $this->indexManager->searchPrefixIn(
                    new IndexEntryList($entries),
                    $index,
                    $prefix['values'],
                    $prefix['range'],
                );
        }

        foreach ($lineNumbers === null ? $conditions : [] as $condition) {
            if (!$this->conditionIndexServable($tableSchema, $condition)) {
                continue;
            }

            $lines = $sorted !== null
                ? $this->indexManager->searchSorted(
                    $tableSchema->name,
                    $sorted,
                    $index,
                    $condition,
                )
                : $this->indexManager->searchLines(
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

        return $lineNumbers;
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
        int | null $keep = null,
    ): array {
        if ($this->cache instanceof NullCache) {
            $records = $this->scanMatching(
                $this->schema->getTable($tableName),
                $conditions,
            );
        } else {
            $records = $this->readAllRaw($tableName);

            if ($conditions !== []) {
                $records = array_values(array_filter(
                    $records,
                    $this->conditionFilter($conditions),
                ));
            }
        }

        if ($ordering !== []) {
            $this->sortByOrdering($records, $ordering, $keep);
        }

        return $records;
    }

    /**
     * O(1) read-side trust gate for the table's indexes, from one read of
     * meta.json: they are used only when the committed byteSize matches
     * the actual data file (the indexes describe exactly the committed
     * state) and the on-disk key format is current. Returns the committed
     * line count the indexes describe, or null on any doubt — missing
     * meta, unknown byteSize, foreign append, legacy format — which
     * degrades reads to a full scan; the next write heals and stamps under
     * the table EX lock.
     */
    private function indexTrustedLineCount(TableSchema $tableSchema): int | null
    {
        try {
            $committed = $this->meta->getCommittedFile($tableSchema->name);
        } catch (JsonProviderException) {
            return null;
        }

        if ($committed['indexFormat'] < 2) {
            $this->logger?->info(
                'table "' . $tableSchema->name . '": pre-v2 index format, '
                    . 'queries fall back to full scans until the next '
                    . 'write rebuilds and stamps the indexes',
            );

            return null;
        }

        if ($committed['byteSize'] === null) {
            return null;
        }

        if (
            !$this->ndjson->exists(
                $tableSchema->name,
                $tableSchema->getFileName(),
            )
        ) {
            return null;
        }

        $freshness = $this->freshness->checkCommitted(
            $tableSchema,
            $committed,
        );
        $this->noteFreshness($tableSchema->name, $freshness);

        if ($freshness === FreshnessEnum::DRIFT) {
            $this->logger?->info(
                'table "' . $tableSchema->name . '": committed byteSize '
                    . 'differs from the data file (foreign append or stale '
                    . 'indexes), queries fall back to full scans until the '
                    . 'next write heals the drift',
            );

            return null;
        }

        return $freshness->trusted() ? $committed['lineCount'] : null;
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
        IndexSnapshot $snapshot,
        TableSchema $tableSchema,
        array $lineNumbers,
        array $conditions,
        int $offset,
        int | null $limit,
    ): array {
        if ($limit === 0) {
            return [];
        }

        $records = $this->values->widenFloats(
            $tableSchema,
            $this->readDataLines($snapshot, $tableSchema, $lineNumbers),
        );
        $result = [];
        $skipped = 0;
        $matches = $this->conditionFilter($conditions);

        foreach ($records as $record) {
            if (!$matches($record)) {
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
                throw new JsonProviderQueryException(
                    JsonProviderErrorEn::QueryUnknownColumn,
                    $tableSchema->name,
                    $order->field,
                    self::CONTEXT_ORDER_BY,
                );
            }
        }

        foreach ($distinctFields as $field) {
            if (!\array_key_exists($field, $tableSchema->columns)) {
                throw new JsonProviderQueryException(
                    JsonProviderErrorEn::QueryUnknownColumn,
                    $tableSchema->name,
                    $field,
                    self::CONTEXT_DISTINCT,
                );
            }
        }
    }

    /**
     * Picks an index for the query: first one matching the requested
     * ordering, then the first one whose leading fields the conditions
     * narrow the most when that is two fields or more (indexPrefix), then
     * one whose first field is filtered by an indexable condition.
     * Service (FK backing) indexes take part like user ones; one goes away
     * with its relation, and a select over the column then falls back to
     * a full scan.
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
        bool $pushPagination = false,
    ): IndexSchema | null {
        if ($tableSchema->indexes === []) {
            return null;
        }

        /*
         * An ordering index pays off only when limit/offset can ride along
         * with it: the engine then walks the index in order and stops at the
         * limit, reading just the rows it returns.
         *
         * Without that cut it reads the whole table BY LINE NUMBER — a linear
         * pass over the data file on top of parsing the index file — and then
         * still filters. That is strictly more work than a plain full scan
         * followed by a sort, and it also steals the choice from a far more
         * selective condition index: `WHERE bucket = 7 ORDER BY title` used to
         * pull all rows in title order to keep a hundred of them.
         *
         * So the ordering index is considered only when pagination can be
         * pushed into it; otherwise the condition index below wins, and the
         * ordering is applied to whatever it returns.
         */
        if (
            $pushPagination
            && $ordering !== []
            && $this->orderingIndexable($tableSchema, $ordering)
        ) {
            foreach ($tableSchema->indexes as $index) {
                if ($index->matchesOrdering($ordering)) {
                    return $index;
                }
            }
        }

        $widest = null;
        $widestFields = 1;

        foreach ($tableSchema->indexes as $index) {
            $prefix = $this->indexPrefix($tableSchema, $index, $conditions);
            $fields = $prefix['fields'];

            if ($fields > $widestFields) {
                $widest = $index;
                $widestFields = $fields;
            }
        }

        if ($widest !== null) {
            return $widest;
        }

        foreach ($conditions as $condition) {
            if (!$this->conditionIndexServable($tableSchema, $condition)) {
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
     * How far the conditions narrow a lookup in the index: the values that
     * `=` conditions fix for its leading fields, in index order, and a
     * range condition on the field right after them. $fields counts the
     * fields used. Only conditions an index may serve take part
     * (conditionIndexServable), and only values a key can encode.
     *
     * @param array<int,FilterCondition> $conditions
     *
     * @return array{
     *     values: array<int,null|bool|float|int|string>,
     *     range: null|FilterCondition,
     *     fields: int,
     * }
     */
    private function indexPrefix(
        TableSchema $tableSchema,
        IndexSchema $index,
        array $conditions,
    ): array {
        $values = [];
        $range = null;

        foreach ($index->fields as $field) {
            $equal = null;
            $bounded = null;

            foreach ($conditions as $condition) {
                if (
                    $condition->not
                    || $condition->field !== $field->field
                    || !$this->conditionIndexServable($tableSchema, $condition)
                ) {
                    continue;
                }

                $value = $condition->value;

                if ($condition->operator === FilterOperatorEnum::EQ) {
                    $encodable = $value === null
                        || (\is_scalar($value)
                            && (!\is_float($value) || is_finite($value)));

                    if ($encodable && $equal === null) {
                        $equal = [$value];
                    }
                } elseif (
                    \in_array($condition->operator, [
                        FilterOperatorEnum::GT,
                        FilterOperatorEnum::GTE,
                        FilterOperatorEnum::LT,
                        FilterOperatorEnum::LTE,
                        FilterOperatorEnum::BETWEEN,
                    ], true)
                ) {
                    $bounded ??= $condition;
                }
            }

            if ($equal !== null) {
                $values[] = $equal[0];

                continue;
            }

            $range = $bounded;

            break;
        }

        return [
            'values' => $values,
            'range'  => $range,
            'fields' => \count($values) + ($range !== null ? 1 : 0),
        ];
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

        return $this->comparisonMode !== ComparisonModeEnum::Locale
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
                $this->comparisonMode === ComparisonModeEnum::Locale
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
     * Checks the unique constraints for a record about to be inserted. A
     * constraint with an index on its fields (UniqueConstraint::indexIn)
     * is checked through it: only the records whose index key equals the
     * incoming one are read, then compared by the constraint's type-strict
     * key — the index key merges 1 and 1.0, so it can only widen the
     * candidates. Without such an index, or when the index cannot be
     * searched in place, the whole table is read once for all the
     * constraints that need it. A record with a null key part passes
     * without a read.
     *
     * @param array<string,null|scalar> $record
     */
    private function checkUniqueOnInsert(
        TableSchema $tableSchema,
        array $record,
    ): void {
        $all = null;
        $lineCount = false;

        foreach ($tableSchema->uniqueConstraints as $constraint) {
            if ($constraint->keyOf($record) === null) {
                continue;
            }

            $index = $constraint->indexIn($tableSchema->indexes);
            $candidates = null;

            if ($index !== null) {
                if ($lineCount === false) {
                    $lineCount = $this->indexTrustedLineCount($tableSchema);
                }

                $candidates = $lineCount === null
                    ? null
                    : $this->uniqueCandidates(
                        $tableSchema,
                        $index,
                        $record,
                        $lineCount,
                    );
            }

            if ($candidates === null) {
                $all ??= $this->readAllForWrite($tableSchema->name);
                $candidates = $all;
            }

            $this->checkOneConstraint(
                $tableSchema,
                $constraint,
                $candidates,
                $record,
                null,
            );
        }
    }

    /**
     * The records whose key in $index equals the key of $record, read
     * through the sorted head and the tail of the index file and checked
     * against the entries that pointed at them; null when the index
     * cannot be searched in place.
     *
     * @param array<string,null|scalar> $record
     *
     * @return null|array<int,array<string,null|scalar>>
     */
    private function uniqueCandidates(
        TableSchema $tableSchema,
        IndexSchema $index,
        array $record,
        int $lineCount,
    ): array | null {
        $sorted = $this->indexManager->openSorted(
            $tableSchema->name,
            $index,
            $lineCount,
        );

        if ($sorted === null) {
            return null;
        }

        $lines = $this->indexManager->searchSortedKey(
            $tableSchema->name,
            $sorted,
            $index,
            IndexKey::build($record, $index),
        );

        if ($lines === []) {
            return [];
        }

        sort($lines);
        $snapshot = $this->dataSnapshot($tableSchema, $lineCount, $sorted, []);
        $records = $this->values->widenFloats(
            $tableSchema,
            $this->readDataLines($snapshot, $tableSchema, $lines),
        );
        $this->indexManager->verifyRecords(
            $tableSchema->name,
            $sorted,
            $index,
            $lines,
            $records,
        );

        return $records;
    }

    /**
     * Rejects a record set in which two records share a non-null key of the
     * constraint (SQL NULL semantics, type-strict keys — same rules as the
     * per-record check).
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    private function assertNoUniqueDuplicates(
        string $tableName,
        UniqueConstraint $constraint,
        array $records,
    ): void {
        $seen = [];

        foreach ($records as $record) {
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

                throw new JsonProviderDataException(
                    JsonProviderErrorEn::UniqueViolation,
                    $tableName,
                    implode(', ', $constraint->fields),
                    implode(', ', $fieldValues),
                );
            }

            $seen[$key] = true;
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

                throw new JsonProviderDataException(
                    JsonProviderErrorEn::UniqueViolation,
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
                throw new JsonProviderDataException(
                    JsonProviderErrorEn::RecordColumnNoDefault,
                    $tableSchema->name,
                    $column,
                    $type,
                );
            }

            $normalized[$column] = ColumnDefaults::forType($type);
        }

        return $normalized;
    }

    /**
     * One test of a record against all the conditions, built once per
     * query (FilterCondition::predicate) and run per row.
     *
     * @param array<int,FilterCondition> $conditions
     *
     * @return \Closure(array<mixed>): bool
     */
    private function conditionFilter(array $conditions): \Closure
    {
        $tests = [];

        foreach ($conditions as $condition) {
            $tests[] = $condition->predicate($this->comparisonMode);
        }

        if (\count($tests) === 1) {
            return $tests[0];
        }

        return static function (array $record) use ($tests): bool {
            foreach ($tests as $test) {
                if (!$test($record)) {
                    return false;
                }
            }

            return true;
        };
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
        } catch (JsonProviderException) {
            $lineCount = 'nometa';
        }

        try {
            $stat = $this->ndjson->fileStat(
                $tableName,
                TableSchema::dataFileName($tableName),
            );
            $fileState = $stat['size'] . '-' . $stat['ino'];
        } catch (JsonProviderException) {
            $fileState = 'nofile';
        }

        return $lineCount . '-' . $fileState;
    }

    /**
     * Reads the storage format of the database once per instance — once
     * per request under PHP-FPM. Refuses a format this engine cannot open,
     * warns about a generation-1 database (it keeps working exactly as
     * under 1.0) and about read-only mode. A path that holds no database
     * yet is left alone.
     *
     * @return list<string> the roCompat features this engine does not know
     */
    private function openStorageFormat(): array
    {
        if (!$this->json->exists(self::SCHEMA_FILE)) {
            return [];
        }

        $manifest = StorageManifest::read($this->dbPath);
        $manifest->assertOpenable($this->dbPath);

        if ($manifest->isLegacy()) {
            $this->warn(
                \sprintf(
                    self::LEGACY_FORMAT_NOTICE,
                    $this->dbPath,
                    StorageManifest::GENERATION,
                ),
                false,
            );

            return [];
        }

        $this->enableGeneration2();
        $readOnly = $manifest->unknownRoCompat();

        if ($readOnly !== []) {
            $this->warn(
                \sprintf(
                    self::READ_ONLY_NOTICE,
                    StorageManifest::describe($readOnly, $this->dbPath),
                ),
                true,
            );
        }

        return $readOnly;
    }

    /**
     * A warning goes to the PSR-3 logger when one is given; without it,
     * to the PHP error log as a user deprecation (the format notice) or a
     * user warning (read-only mode), so it is never silently lost.
     */
    private function warn(string $message, bool $severe): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);

            return;
        }

        trigger_error($message, $severe ? E_USER_WARNING : E_USER_DEPRECATED);
    }

    /**
     * The body of migrateStorage(), under the database and table locks.
     */
    private function migrateLocked(int $target): MigrationReport
    {
        $from = StorageManifest::read($this->dbPath)->generation;

        if ($target < $from || $target > StorageManifest::GENERATION) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::StorageMigrationTargetInvalid,
                (string)$target,
                (string)$from,
                (string)StorageManifest::GENERATION,
            );
        }

        $this->migrating = true;
        $steps = [];
        $refreshed = [];
        $skipped = [];

        try {
            for ($generation = $from; $generation < $target; $generation++) {
                $this->migrateStep($generation, $refreshed, $skipped);
                $steps[] = self::stepName($generation);
            }

            if ($steps === [] && $from >= StorageManifest::GENERATION) {
                $this->refreshAllTables($refreshed, $skipped);
                $manifest = StorageManifest::read($this->dbPath);

                if ($manifest->missingFeatures() !== []) {
                    $manifest->withFeatures()->write($this->dbPath);
                }
            }
        } finally {
            $this->migrating = false;
        }

        $this->freshnessNoted = [];

        return new MigrationReport(
            fromGeneration: $from,
            toGeneration: max($from, $target),
            steps: $steps,
            refreshedTables: $refreshed,
            skippedTables: $skipped,
        );
    }

    /**
     * Runs the one step leading from $generation to the next.
     *
     * @param list<string> $refreshed
     * @param list<string> $skipped
     */
    private function migrateStep(
        int $generation,
        array &$refreshed,
        array &$skipped,
    ): void {
        if ($generation === 1) {
            $this->migrateGeneration1To2($refreshed, $skipped);

            return;
        }

        throw new JsonProviderServiceException(
            JsonProviderErrorEn::StorageMigrationTargetInvalid,
            (string)($generation + 1),
            (string)$generation,
            (string)StorageManifest::GENERATION,
        );
    }

    /**
     * Generation 1 -> 2: every table gets its indexes rebuilt from the data
     * (never taken on faith — a stale index would otherwise be stamped as
     * fresh) and the inode stamp; the manifest comes last.
     *
     * @param list<string> $refreshed
     * @param list<string> $skipped
     */
    private function migrateGeneration1To2(
        array &$refreshed,
        array &$skipped,
    ): void {
        $this->enableGeneration2();
        $this->refreshAllTables($refreshed, $skipped);
        StorageManifest::current()->write($this->dbPath);
    }

    /**
     * @param list<string> $refreshed
     * @param list<string> $skipped
     */
    private function refreshAllTables(array &$refreshed, array &$skipped): void
    {
        foreach ($this->schema->getTables() as $name => $tableSchema) {
            $result = $this->bringTableCurrent($tableSchema);

            if ($result === true) {
                $refreshed[] = $name;
            } elseif ($result === false) {
                $skipped[] = $name;
            }
        }
    }

    /**
     * Makes one table fresh. Drifted counters are healed by the canonical
     * full rewrite; a stale or missing stamp only needs the indexes
     * rebuilt from the data and stamped — the data file stays as it is. A
     * table holding a line that is not a record is left alone: a rewrite
     * would drop that line, and indexes built around it cannot be
     * certified. Returns null when the table was already fresh, false when
     * it was left alone.
     */
    private function bringTableCurrent(TableSchema $tableSchema): bool | null
    {
        $freshness = $this->freshness->check($tableSchema);

        if ($freshness === FreshnessEnum::FRESH) {
            if ($this->derivedCurrent($tableSchema)) {
                return null;
            }

            $this->rebuildDerived($tableSchema);

            return true;
        }

        if ($this->freshness->holdsBrokenRecords($tableSchema)) {
            return false;
        }

        if ($freshness === FreshnessEnum::DRIFT) {
            $this->ensureTableConsistent($tableSchema);

            return true;
        }

        return $this->freshness->refresh($tableSchema);
    }

    private static function stepName(int $generation): string
    {
        return $generation . '->' . ($generation + 1);
    }

    /**
     * The write-path gate: true when the committed snapshot still
     * describes the data file, so the indexes may be trusted as they are.
     *
     * @param array{
     *     lineCount: int,
     *     byteSize: null|int,
     *     dataIno: null|int,
     *     indexFormat: int,
     * } $committed
     */
    private function trustedNow(
        TableSchema $tableSchema,
        array $committed,
    ): bool {
        $freshness = $this->freshness->checkCommitted(
            $tableSchema,
            $committed,
        );
        $this->noteFreshness($tableSchema->name, $freshness);

        return $freshness->trusted();
    }

    /**
     * Reports a table that is unstamped or stale, once per instance.
     */
    private function noteFreshness(
        string $tableName,
        FreshnessEnum $freshness,
    ): void {
        $notice = match ($freshness) {
            FreshnessEnum::UNSTAMPED => self::UNSTAMPED_NOTICE,
            FreshnessEnum::STALE     => self::STALE_NOTICE,
            default                  => null,
        };

        if (
            $notice === null
            || $this->migrating
            || isset($this->freshnessNoted[$tableName])
        ) {
            return;
        }

        $this->freshnessNoted[$tableName] = true;
        $this->warn(\sprintf($notice, $tableName), false);
    }

    /**
     * Turns on what a generation-2 database maintains: the table stamps and
     * the derived lookup files, which follow every committed rewrite and
     * append.
     */
    private function enableGeneration2(): void
    {
        $this->freshness->enableStamps();
        $this->derived->enable();
        $this->derived->prepare();
        $this->meta->onCommit(
            function (string $table): void {
                $this->derived->rebuildLineOffsets(
                    $table,
                    $this->dataPath($table),
                );
            },
            function (string $table, int $lineCount, int $byteSize): void {
                $this->derived->appendLineOffset(
                    $table,
                    $this->dataPath($table),
                    $lineCount,
                    $byteSize,
                );
            },
        );
    }

    /**
     * Whether the table's derived lookup files describe its files as they
     * are now.
     */
    private function derivedCurrent(TableSchema $tableSchema): bool
    {
        try {
            $lineCount = $this->meta->getLineCount($tableSchema->name);
        } catch (JsonProviderException) {
            return false;
        }

        $indexFiles = [];

        foreach ($tableSchema->indexes as $index) {
            $indexFiles[$index->getFileName()] = $this->ndjson->pathOf(
                $tableSchema->name,
                $index->getFileName(),
            );
        }

        return $this->derived->tableCurrent(
            $tableSchema->name,
            $this->dataPath($tableSchema->name),
            $lineCount,
            $indexFiles,
        );
    }

    /**
     * Builds the derived lookup files of a fresh table: the line offsets
     * from the data file, a sorted head for every index file (rewriting it
     * sorted when no head is recorded).
     */
    private function rebuildDerived(TableSchema $tableSchema): void
    {
        $lineCount = $this->meta->getLineCount($tableSchema->name);
        $this->derived->rebuildLineOffsets(
            $tableSchema->name,
            $this->dataPath($tableSchema->name),
        );

        foreach ($tableSchema->indexes as $index) {
            $this->indexManager->mergeTail(
                $tableSchema->name,
                $index,
                $lineCount,
                $lineCount,
            );
        }
    }

    private function dataPath(string $table): string
    {
        return $this->ndjson->pathOf($table, TableSchema::dataFileName($table));
    }

    /**
     * Rewrites sorted every index of the table whose appended tail grew
     * past $indexTailLimit entries.
     */
    private function mergeIndexTails(
        TableSchema $tableSchema,
        int $lineCount,
    ): void {
        foreach ($tableSchema->indexes as $index) {
            $this->indexManager->mergeTail(
                $tableSchema->name,
                $index,
                $lineCount,
                $this->indexTailLimit,
            );
        }
    }

    /**
     * The data lines by number: through the line offsets when few lines are
     * wanted and the offsets describe the data file as it is, by walking
     * the file otherwise — one seek per line costs more than a walk once a
     * large share of the file is wanted.
     *
     * @param array<int,int> $lineNumbers
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function readDataLines(
        IndexSnapshot $snapshot,
        TableSchema $tableSchema,
        array $lineNumbers,
    ): array {
        $offsets = $this->offsetsOf($snapshot, $tableSchema, $lineNumbers);

        return $offsets === null
            ? NdjsonStorage::readLinesFrom(
                $snapshot->data,
                $snapshot->dataSize,
                $lineNumbers,
            )
            : array_values(iterator_to_array(
                NdjsonStorage::recordsAtFrom($snapshot->data, $offsets),
            ));
    }

    /**
     * Refuses any write while the storage format carries a roCompat
     * feature this engine does not know.
     */
    private function assertWritable(): void
    {
        if ($this->readOnlyFeatures === []) {
            return;
        }

        throw new JsonProviderServiceException(
            JsonProviderErrorEn::StorageReadOnly,
            StorageManifest::describe($this->readOnlyFeatures, $this->dbPath),
        );
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
                $this->freshness,
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
                $this->freshness,
                fn (): FkBackingPolicyEnum => $this->fkBackingPolicy,
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
                $this->freshness,
                fn (TableSchema $t) => $this->ensureTableConsistent($t),
                fn (string $t): array => $this->readAllForWrite($t),
                fn (string $t) => $this->assertRewritable($t),
                fn (string $t, string $mode): bool => $this->locks->isHeld(
                    $t,
                    $mode,
                ),
                fn (string $t) => $this->invalidateCache($t),
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
                $this->validator(),
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
