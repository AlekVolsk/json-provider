<?php

declare(strict_types=1);

namespace AV\JsonProvider;

use AV\JsonProvider\Cache\CacheInterface;
use AV\JsonProvider\Cache\NullCache;
use AV\JsonProvider\Engine\Context;
use AV\JsonProvider\Engine\Parts;
use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Exception\JsonProviderMappingException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Exception\Locale\LocaleInterface;
use AV\JsonProvider\Mapping\DtoMap;
use AV\JsonProvider\Query\ComparisonModeEnum;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Relations\FkEngine;
use AV\JsonProvider\Schema\FkBackingPolicyEnum;
use AV\JsonProvider\Schema\IdentifierRules;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\TableDiff;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Services\Backup\Backup;
use AV\JsonProvider\Services\Backup\Restore;
use AV\JsonProvider\Services\Format\MigrationReport;
use AV\JsonProvider\Services\Format\StorageStatus;
use AV\JsonProvider\Services\Integrity\IntegrityReport;
use AV\JsonProvider\Storage\BrokenRecordPolicyEnum;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\StorageManifest;
use Psr\Log\LoggerInterface;

/**
 * Public entry point of the engine: tables, queries, mutations, schema
 * changes, maintenance. The work is done by the internal classes of the
 * Engine namespace; this class holds the public contract.
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

    private readonly Context $context;
    private readonly Parts $parts;

    private function __construct(
        string $dbPath,
        CacheInterface | null $cache = null,
        LoggerInterface | null $logger = null,
    ) {
        if ($logger !== null) {
            JsonProviderException::setLogger($logger);
        }

        $real = realpath($dbPath);
        $this->context = new Context(
            $dbPath,
            'jdp:' . self::CACHE_FORMAT_VERSION . ':'
                . substr(sha1($real === false ? $dbPath : $real), 0, 16) . ':',
            $cache ?? new NullCache(),
            $logger,
        );
        $this->parts = new Parts($this->context);
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
        $dbPath = Context::normalizePath($dbPath);

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
        return (new JsonStorage(Context::normalizePath($dbPath)))
            ->exists(Context::SCHEMA_FILE);
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
        $dbPath = Context::normalizePath($dbPath);
        $bootstrap = JsonStorage::createRoot($dbPath);
        $bootstrap->createFile(
            Context::SCHEMA_FILE,
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
        $this->context->comparisonMode = $mode;

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
        $this->context->brokenRecordPolicy = $policy;

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
        $this->context->fkBackingPolicy = $policy;

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
            $this->context->dtoRegistry->register(DtoMap::compile(
                $class,
                $this->context->schema->getTable($table),
            ));
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

            if ($this->context->dtoRegistry->forTable($table) === null) {
                throw new JsonProviderMappingException(
                    JsonProviderErrorEn::DtoNotRegistered,
                    $table,
                );
            }

            $this->context->dtoRegistry->unregister($table);
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
            $this->context->schema->getTable($tableName),
            $this->context->dtoRegistry->forTable($tableName),
            $this->context->dtoMapper,
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
        $this->parts->schemaChanges()->createTable($tableSchema);
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
        $this->parts->schemaChanges()->dropTable($tableName);
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
        $this->parts->schemaChanges()->renameTable($from, $to);
    }

    /**
     * Whether a table is registered in the schema.
     */
    public function hasTable(string $tableName): bool
    {
        return $this->context->schema->hasTable($tableName);
    }

    /**
     * Names of all tables registered in the schema.
     *
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_keys($this->context->schema->getTables());
    }

    /**
     * Column names of a table in schema order (the primary key comes first).
     * Throws if the table does not exist.
     *
     * @return list<string>
     */
    public function columnNames(string $tableName): array
    {
        return array_keys(
            $this->context->schema->getTable($tableName)->columns,
        );
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
        return $this->parts->schemaChanges()->migrateColumns($desired);
    }

    /**
     * Reads all records from a table (with cache).
     *
     * @return array<int,array<string,null|scalar>>
     */
    public function readAll(string $tableName): array
    {
        return $this->parts->reader()->readAll($tableName);
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
        return $this->parts->writer()->insert($tableName, $record);
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
        return $this->parts->writer()->update($tableName, $conditions, $data);
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
        return $this->parts->writer()->delete($tableName, $conditions);
    }

    /**
     * Returns the most recently allocated auto-increment id for the table.
     * 0 on an empty table (no inserts ever). Not rolled back on delete:
     * ids are never reused (cf. SQL AUTO_INCREMENT).
     * O(1) — reads meta.json.
     */
    public function getLastInsertedId(string $tableName): int
    {
        return $this->context->meta->getLastInsertedId($tableName);
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
        return $this->context->meta->getNextId($tableName);
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
        $this->parts->schemaChanges()->reorderColumns($tableName, $newOrder);
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
        $this->parts->writer()->truncate($tableName);
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
        return $this->parts->writer()->importRecords($tableName, $records);
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
        $this->parts->schemaChanges()->renameColumn($tableName, $from, $to);
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
        $this->parts->schemaChanges()->setTableComment($tableName, $comment);
    }

    /**
     * Returns the table comment, or null if it has none.
     */
    public function getTableComment(string $tableName): string | null
    {
        return $this->context->schema->getTable($tableName)->tableComment;
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
        $this->parts->schemaChanges()
            ->setColumnComment($tableName, $column, $comment);
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
        $this->parts->schemaChanges()
            ->setColumnComments($tableName, $comments, $merge);
    }

    /**
     * Returns the comment for a single column, or null if it has none (or the
     * column does not exist).
     */
    public function getColumnComment(
        string $tableName,
        string $column,
    ): string | null {
        return $this->context->schema->getTable($tableName)
            ->getColumnComment($column);
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
        return $this->context->schema->getTable($tableName)->columnComment;
    }

    /**
     * The table's schema as declared — what createTable() takes to create
     * it: the columns, the primary key index, user indexes, unique
     * constraints and comments. The service indexes of relations are left
     * out: addRelation() provisions them, relations() lists the relations.
     * Throws if the table does not exist.
     */
    public function getTableSchema(string $tableName): TableSchema
    {
        return $this->context->schema->getTable($tableName)
            ->withoutServiceIndexes();
    }

    /**
     * What separates the table named by $desired from that schema: the
     * columns, indexes and unique constraints to add, drop or change (see
     * TableDiff). Reads the schema only, changes nothing; applying the
     * difference is up to the caller — migrateColumns(), addIndex() and
     * the rest, each a change of its own. Throws if the table does not
     * exist.
     */
    public function diffTable(TableSchema $desired): TableDiff
    {
        return TableDiff::between(
            $this->context->schema->getTable($desired->name),
            $desired,
        );
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
        $tableSchema = $this->context->schema->getTable($tableName);
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
        $this->parts->indexChanges()->rebuildIndex($tableName, $indexName);
    }

    /**
     * Rebuilds every index of the table from current data, including PK,
     * and stamps the current index format. Sequential per index; each
     * rebuild atomically replaces its own file.
     */
    public function rebuildAllIndexes(string $tableName): void
    {
        $this->parts->indexChanges()->rebuildAllIndexes($tableName);
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
        $this->parts->indexChanges()->addIndex($tableName, $index);
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
        $this->parts->indexChanges()->dropIndex($tableName, $indexName);
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
        $this->parts->indexChanges()
            ->addUniqueConstraint($tableName, $constraint);
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
        $this->parts->indexChanges()->dropUniqueConstraint($tableName, $name);
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
        $this->parts->indexChanges()->addRelation($relation);
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
        $this->parts->indexChanges()
            ->dropRelation($fromTable, $foreignKey, $toTable);
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
            ? $this->context->schema->getAllRelations()
            : $this->context->schema->getRelations($tableName);
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
        $this->parts->maintenance()->optimizeTable($tableName);
    }

    /**
     * Validates a single table against the storage contract; returns a report.
     * Read-only — never mutates anything.
     */
    public function validateTable(string $tableName): IntegrityReport
    {
        return $this->parts->maintenance()->validateTable($tableName);
    }

    /**
     * Validates the entire database; returns a report.
     * Read-only — never mutates anything.
     */
    public function validate(): IntegrityReport
    {
        return $this->parts->maintenance()->validate();
    }

    /**
     * Repairs a single table where possible; returns the report listing
     * all findings together with their repair status.
     * Cache for the table is invalidated.
     */
    public function repairTable(string $tableName): IntegrityReport
    {
        return $this->parts->maintenance()->repairTable($tableName);
    }

    /**
     * Repairs the entire database where possible; returns the full report.
     * Runs under the database EX lock plus EX on every schema table.
     * All per-table caches are invalidated.
     */
    public function repair(): IntegrityReport
    {
        return $this->parts->maintenance()->repair();
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
        return $this->parts->maintenance()->backup($destination);
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
        $this->parts->maintenance()->restore(
            $archivePath,
            $adoptArchivedSchema,
            $pruneExtraTables,
        );
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
        return $this->parts->maintenance()->storageStatus();
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
        return $this->parts->maintenance()->migrateStorage($toGeneration);
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
        return $this->parts->reader()->select(
            $tableName,
            $conditions,
            $ordering,
            $limit,
            $offset,
            $distinctFields,
        );
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
        return $this->parts->reader()->count($tableName, $conditions);
    }

    /**
     * Invalidates the cache entry of the table's CURRENT version tag —
     * the manual escape hatch for the documented same-size-update blind
     * spot of the version-tagged keys; entries under other (stale) tags
     * are already unreachable and expire by TTL/eviction.
     */
    public function invalidateCache(string $tableName): void
    {
        $this->parts->store()->invalidateCache($tableName);
    }

    /**
     * Removes every cache entry of THIS database from the backend (the
     * per-database key namespace scopes the flush) — other databases and
     * other applications sharing the pool are untouched.
     */
    public function flushDb(): void
    {
        $this->context->cache->flushDb($this->context->cacheNs);
    }
}
