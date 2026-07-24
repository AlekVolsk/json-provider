<?php

declare(strict_types=1);

namespace AV\JsonProvider\Relations;

use AV\JsonProvider\Cache\CacheInterface;
use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\PreparedRewrite;
use AV\JsonProvider\Validation\ColumnTypeInfo;
use AV\JsonProvider\Validation\ValueValidator;

/**
 * FK cascade engine: plans and applies the multi-table effects of a
 * delete or update as one validated write set.
 *
 * The plan phase walks the canonical relations graph breadth-first from
 * the mutated root rows, reading every affected table EXACTLY once from
 * disk under the locks the caller already holds (never the cache). All
 * validation — executable-edge schema checks, SET NULL nullability,
 * cascade patch encoding, restrict probes, unique checks of the final
 * state — happens in this phase, before a single byte is written. Rows
 * already collected for deletion are never re-expanded, so cyclic
 * (A<->B) and self-referential cascades terminate without a depth limit.
 *
 * RESTRICT follows MySQL's immediate semantics: the probe runs against
 * the ORIGINAL child state, so a violation counts even when the
 * referencing child row is itself deleted by the same statement — a
 * self-referential restrict table cannot be emptied by one delete-all;
 * delete leaves before roots.
 *
 * Existence probes ride the relation's backing index and pass the same
 * trust pipeline as the select path (byteSize/format gate, then full
 * structural validation): a stale index silently degrades the probe to a
 * scan of the already-read rows, a structurally corrupt one raises
 * INDEX_UNRELIABLE. A restrict edge with no covering backing index is a
 * configuration error (FK_BACKING_INDEX_MISSING), never a silent scan.
 *
 * The apply phase is two-phase: PREPARE encodes and fsyncs every table
 * to a temp sibling (any failure aborts with all data files untouched),
 * COMMIT renames children before parents, then rebuilds indexes and
 * commits meta/cache per table. A crash between renames leaves every
 * table individually complete; cross-table convergence comes from
 * re-running the statement (child-first order makes it idempotent) and
 * per-table self-healing.
 *
 * The engine reuses the provider's private consistency helpers through
 * injected closures: $ensureConsistent self-heals a table before its
 * first read (write-locked tables only), $readForWrite reads the
 * on-disk records with float widening, $cacheKey builds the cache key of
 * a table.
 *
 * @phpstan-type RecordSet array<int,array<string,null|scalar>>
 */
final class FkEngine
{
    /** @var array<string,TableSchema> */
    private array $ctxSchemas = [];

    /** @var array<string,array<int,array<string,null|scalar>>> */
    private array $ctxRecords = [];

    /** @var array<string,array<int,true>> */
    private array $ctxDeleted = [];

    /** @var array<string,array<int,array<string,null|scalar>>> */
    private array $ctxPatches = [];

    /** @var array<int,string> */
    private array $ctxOrder = [];

    /** @var array<string,true> */
    private array $ctxWriteSet = [];

    /** @var array<string,true> */
    private array $ctxForceWrite = [];

    /** @var array<string,true> */
    private array $ctxValidatedEdges = [];

    /** @var array<string,array<string,array<string,array<int,int>>>> */
    private array $ctxFkLookup = [];

    /**
     * @var array<string,array<string,array<int,array{key:string,
     *      line:int}>|false>>
     */
    private array $ctxProbeEntries = [];

    /**
     * @param \Closure(TableSchema): void $ensureConsistent
     * @param \Closure(string): RecordSet $readForWrite
     * @param \Closure(string): string    $cacheKey
     */
    public function __construct(
        private readonly SchemaRegistry $schema,
        private readonly MetaRegistry $meta,
        private readonly NdjsonStorage $ndjson,
        private readonly IndexManager $indexManager,
        private readonly ValueValidator $values,
        private readonly CacheInterface $cache,
        private readonly \Closure $ensureConsistent,
        private readonly \Closure $readForWrite,
        private readonly \Closure $cacheKey,
    ) {}

    /**
     * Lock plan for an update/delete of the given table: the table EX,
     * transitively every CASCADE/SET_NULL child EX (their rows are
     * rewritten and their own children may cascade further), every
     * RESTRICT child SH (their rows are only read), and SH on the parents
     * of every EX table. Derived from the relations graph of the schema,
     * not from data; cycles terminate via the visited set.
     *
     * @return array<string,string>
     */
    public function mutationLockPlan(TableSchema $tableSchema): array
    {
        $plan = [$tableSchema->name => 'ex'];
        $queue = [$tableSchema->name];
        $visited = [$tableSchema->name => true];

        while ($queue !== []) {
            $table = array_shift($queue);
            $plan = $this->addParentShLocks($table, $plan);

            foreach ($this->schema->getChildRelations($table) as $relation) {
                $child = $relation->childTable();
                $actions = [$relation->onDelete, $relation->onUpdate];

                if (
                    \in_array(ForeignKeyActionEnum::CASCADE, $actions, true)
                    || \in_array(
                        ForeignKeyActionEnum::SET_NULL,
                        $actions,
                        true,
                    )
                ) {
                    $plan[$child] = 'ex';

                    if (!isset($visited[$child])) {
                        $visited[$child] = true;
                        $queue[] = $child;
                    }
                } elseif (
                    \in_array(ForeignKeyActionEnum::RESTRICT, $actions, true)
                ) {
                    $plan[$child] ??= 'sh';
                }
            }
        }

        return $plan;
    }

    /**
     * Lock plan for an insert: the table itself EX plus SH on parents of
     * its enforced relations (rows of the parents provide FK context).
     *
     * @return array<string,string>
     */
    public function insertLockPlan(TableSchema $tableSchema): array
    {
        return $this->addParentShLocks(
            $tableSchema->name,
            [$tableSchema->name => 'ex'],
        );
    }

    /**
     * Plans the FK closure of deleting the given root rows. The caller
     * has already read the root under its EX lock; every other affected
     * table is read here exactly once. Returns the validated write set —
     * nothing has touched the disk yet.
     *
     * @param array<int,array<string,null|scalar>> $rootRecords
     * @param array<int,int>                       $deleteIndexes
     */
    public function planFkDelete(
        TableSchema $rootSchema,
        array $rootRecords,
        array $deleteIndexes,
    ): FkWritePlan {
        $this->resetContext($rootSchema, $rootRecords);
        $root = $rootSchema->name;
        $queue = [];
        $updateQueue = [];

        foreach ($deleteIndexes as $index) {
            $this->ctxDeleted[$root][$index] = true;
            $queue[] = [$root, $index];
        }

        while ($queue !== []) {
            [$table, $index] = array_shift($queue);
            $row = $this->ctxRecords[$table][$index];

            foreach ($this->schema->getChildRelations($table) as $relation) {
                $action = $relation->onDelete;

                if ($action === ForeignKeyActionEnum::NO_ACTION) {
                    continue;
                }

                $this->assertEdgeExecutable($relation, $action);
                $parentValue = $row[$relation->parentColumn()] ?? null;

                if ($parentValue === null) {
                    continue;
                }

                if ($action === ForeignKeyActionEnum::RESTRICT) {
                    $this->assertNoChildReferences(
                        $relation,
                        $parentValue,
                        $table,
                    );
                } elseif ($action === ForeignKeyActionEnum::CASCADE) {
                    $cascaded = $this->cascadeDeleteChildren(
                        $relation,
                        $parentValue,
                    );
                    array_push($queue, ...$cascaded);
                } else {
                    $propagated = $this->setNullChildren(
                        $relation,
                        $parentValue,
                    );
                    array_push($updateQueue, ...$propagated);
                }
            }
        }

        /*
         * A SET_NULL patch is a value change of the child column: edges
         * of GRANDCHILDREN referencing that column via onUpdate must see
         * it — a restrict grandchild blocks the delete, cascade/setNull
         * ones follow the null. Without this closure the declared
         * restrict would be silently bypassed and cascading grandchildren
         * would keep dangling references.
         */
        $this->runUpdateClosure($updateQueue);
        $this->checkUniqueFinalState();

        return $this->assemblePlan($root, \count($deleteIndexes));
    }

    /**
     * Plans the FK closure of updating the given root target rows with
     * the already encoded patch. Unique constraints of every table whose
     * rows change are checked against the FINAL state (other patched rows
     * of the same statement included) before the plan is returned.
     *
     * @param array<int,array<string,null|scalar>> $rootRecords
     * @param array<int,int>                       $targetIndexes
     * @param array<string,null|scalar>            $patch
     */
    public function planFkUpdate(
        TableSchema $rootSchema,
        array $rootRecords,
        array $targetIndexes,
        array $patch,
    ): FkWritePlan {
        $this->resetContext($rootSchema, $rootRecords);
        $root = $rootSchema->name;
        $this->ctxForceWrite[$root] = true;
        $queue = [];

        foreach ($targetIndexes as $index) {
            $row = $this->ctxRecords[$root][$index];

            foreach ($patch as $column => $newValue) {
                $oldValue = $row[$column] ?? null;

                if ($oldValue === $newValue) {
                    continue;
                }

                $this->patchRow($root, $index, $column, $newValue);
                $queue[] = [$root, $column, $oldValue, $newValue];
            }
        }

        $this->runUpdateClosure($queue);
        $this->checkUniqueFinalState();

        return $this->assemblePlan($root, \count($targetIndexes));
    }

    /**
     * Applies a validated plan: PREPARE every table (encode + temp file +
     * fsync; any failure aborts with all data files untouched), COMMIT by
     * renaming children before parents, then per table rebuild indexes,
     * commit meta counters and refresh the cache.
     */
    public function applyFkPlan(FkWritePlan $plan): void
    {
        /** @var array<string,PreparedRewrite> $prepared */
        $prepared = [];

        try {
            foreach ($plan->writeOrder as $table) {
                $prepared[$table] = $this->ndjson->prepareRewrite(
                    $table,
                    $plan->schemas[$table]->getFileName(),
                    $plan->tables[$table],
                );
            }
        } catch (\Throwable $e) {
            foreach ($prepared as $abort) {
                $this->ndjson->abortPrepared($abort);
            }

            throw $e;
        }

        /*
         * The caches are dropped BEFORE the files flip: a failure
         * anywhere in the commit tail (index rebuild, meta, an external
         * cache adapter) must not leave this process serving pre-plan
         * rows from a warm cache over already-replaced files. The
         * refreshed entries are set per table at the very end.
         */
        foreach ($plan->writeOrder as $table) {
            $this->cache->invalidate(($this->cacheKey)($table));
        }

        foreach ($plan->writeOrder as $table) {
            $this->ndjson->commitPrepared($prepared[$table]);
        }

        foreach ($plan->writeOrder as $table) {
            $records = $plan->tables[$table];
            $this->indexManager->rebuild($plan->schemas[$table], $records);
            $this->meta->commitRewrite(
                $table,
                \count($records),
                $prepared[$table]->byteSize,
            );

            if ($this->meta->getIndexFormat($table) < 2) {
                $this->meta->stampIndexFormat($table, 2);
            }

            $this->cache->set(($this->cacheKey)($table), $records);
        }
    }

    /**
     * Transitive closure of value changes over onUpdate edges: each
     * queue entry is one (table, column, old, new) transition; cascade
     * and setNull patches enqueue the transitions they cause in the
     * children, restrict probes the old value. Shared by planFkUpdate
     * (seeded with the user patch) and planFkDelete (seeded with the
     * SET_NULL patches of the delete walk).
     *
     * @param array<int,array{string,string,null|scalar,null|scalar}> $queue
     */
    private function runUpdateClosure(array $queue): void
    {
        while ($queue !== []) {
            [$table, $column, $oldValue, $newValue] = array_shift($queue);

            foreach ($this->schema->getChildRelations($table) as $relation) {
                if ($relation->parentColumn() !== $column) {
                    continue;
                }

                $action = $relation->onUpdate;

                if ($action === ForeignKeyActionEnum::NO_ACTION) {
                    continue;
                }

                $this->assertEdgeExecutable($relation, $action);

                if ($oldValue === null) {
                    continue;
                }

                if ($action === ForeignKeyActionEnum::RESTRICT) {
                    $this->assertNoChildReferences(
                        $relation,
                        $oldValue,
                        $table,
                    );
                } elseif ($action === ForeignKeyActionEnum::CASCADE) {
                    $propagated = $this->cascadeUpdateChildren(
                        $relation,
                        $oldValue,
                        $newValue,
                    );
                    array_push($queue, ...$propagated);
                } else {
                    $propagated = $this->setNullOnUpdate(
                        $relation,
                        $oldValue,
                    );
                    array_push($queue, ...$propagated);
                }
            }
        }
    }

    // -- plan phase internals ----------------------------------------------

    /**
     * @param array<int,array<string,null|scalar>> $rootRecords
     */
    private function resetContext(
        TableSchema $rootSchema,
        array $rootRecords,
    ): void {
        $root = $rootSchema->name;
        $this->ctxSchemas = [$root => $rootSchema];
        $this->ctxRecords = [$root => $rootRecords];
        $this->ctxDeleted = [$root => []];
        $this->ctxPatches = [$root => []];
        $this->ctxOrder = [$root];
        $this->ctxForceWrite = [];
        $this->ctxValidatedEdges = [];
        $this->ctxFkLookup = [];
        $this->ctxProbeEntries = [];
        $this->ctxWriteSet = [];

        foreach ($this->mutationLockPlan($rootSchema) as $table => $mode) {
            if ($mode === 'ex') {
                $this->ctxWriteSet[$table] = true;
            }
        }
    }

    /**
     * Loads a table into the plan context: schema, then (for tables the
     * lock plan allows to be written) the self-healing consistency gate,
     * then one on-disk read. Restrict-only children are held under SH
     * locks, so they are read as-is — healing writes and is not allowed
     * there.
     */
    private function loadTable(string $table): void
    {
        if (isset($this->ctxRecords[$table])) {
            return;
        }

        $tableSchema = $this->tableSchema($table);

        if (isset($this->ctxWriteSet[$table])) {
            ($this->ensureConsistent)($tableSchema);
        }

        $this->ctxRecords[$table] = ($this->readForWrite)($table);
        $this->ctxDeleted[$table] = [];
        $this->ctxPatches[$table] = [];
        $this->ctxOrder[] = $table;
    }

    /**
     * Record indexes of the table whose ORIGINAL value of the column
     * matches the given value. The lookup is built once per (table,
     * column) with the same type-strict canonicalization the unique keys
     * use (keyPart is `===`-equivalent over stored scalars, including the
     * -0.0/0.0 fold), so repeated cascade steps cost O(matches), not a
     * table scan per deleted parent row. Patches never invalidate it —
     * cascade matching is defined over the original state.
     *
     * @return array<int,int>
     */
    private function childIndexesByValue(
        string $table,
        string $column,
        bool | float | int | string $value,
    ): array {
        if (!isset($this->ctxFkLookup[$table][$column])) {
            $lookup = [];

            foreach ($this->ctxRecords[$table] as $index => $row) {
                $stored = $row[$column] ?? null;

                if ($stored === null) {
                    continue;
                }

                $lookup[UniqueConstraint::keyPart($stored)][] = $index;
            }

            $this->ctxFkLookup[$table][$column] = $lookup;
        }

        return $this->ctxFkLookup[$table][$column][
            UniqueConstraint::keyPart($value)
        ] ?? [];
    }

    private function tableSchema(string $table): TableSchema
    {
        return $this->ctxSchemas[$table]
            ??= $this->schema->getTable($table);
    }

    /**
     * Validates an edge the current statement is about to execute:
     * both canonical columns exist, base types match (the "|null" suffix
     * is ignored), SET NULL lands on a nullable column. Dead NO_ACTION
     * edges are never validated — a broken but inert legacy edge must not
     * make working deletes fail. Memoized per edge and action.
     */
    private function assertEdgeExecutable(
        RelationSchema $relation,
        ForeignKeyActionEnum $action,
    ): void {
        $memoKey = spl_object_id($relation) . ':' . $action->value;

        if (isset($this->ctxValidatedEdges[$memoKey])) {
            return;
        }

        $childTable = $relation->childTable();
        $parentTable = $relation->parentTable();
        $childSchema = $this->tableSchema($childTable);
        $parentSchema = $this->tableSchema($parentTable);

        foreach (
            [
                [$relation->childColumn(), $childTable, $childSchema],
                [$relation->parentColumn(), $parentTable, $parentSchema],
            ] as [$column, $holder, $holderSchema]
        ) {
            if (!\array_key_exists($column, $holderSchema->columns)) {
                throw StorageException::relationColumnNotFound(
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
            $childSchema->columns[$relation->childColumn()],
        );
        $parentInfo = ColumnTypeInfo::parse(
            $parentSchema->columns[$relation->parentColumn()],
        );

        if ($childInfo->base !== $parentInfo->base) {
            throw StorageException::relationTypeMismatch(
                $childTable,
                $relation->childColumn(),
                $childSchema->columns[$relation->childColumn()],
                $parentTable,
                $relation->parentColumn(),
                $parentSchema->columns[$relation->parentColumn()],
            );
        }

        if (
            $action === ForeignKeyActionEnum::SET_NULL
            && !$childInfo->nullable
        ) {
            throw StorageException::foreignKeySetNullNotNullable(
                $childTable,
                $relation->childColumn(),
            );
        }

        $this->ctxValidatedEdges[$memoKey] = true;
    }

    /**
     * RESTRICT probe against the ORIGINAL child state. Rides the trusted
     * backing index when possible; degrades to a scan of the one-time
     * read when the index is stale or pre-v2. No covering backing index
     * at all is a configuration error, never a silent scan.
     */
    private function assertNoChildReferences(
        RelationSchema $relation,
        bool | float | int | string $value,
        string $parentTable,
    ): void {
        $entries = $this->trustedBackingEntries($relation, true);

        if ($entries !== null) {
            [$index, $indexEntries] = $entries;

            if (
                $this->indexManager->eqExists($index, $indexEntries, $value)
            ) {
                throw StorageException::foreignKeyRestrict(
                    $relation->childTable(),
                    $relation->childColumn(),
                    $parentTable,
                );
            }

            return;
        }

        $child = $relation->childTable();
        $this->loadTable($child);

        if (
            $this->childIndexesByValue(
                $child,
                $relation->childColumn(),
                $value,
            ) !== []
        ) {
            throw StorageException::foreignKeyRestrict(
                $child,
                $relation->childColumn(),
                $parentTable,
            );
        }
    }

    /**
     * Whether a TRUSTED backing index proves that no child row references
     * the value — the short-circuit that lets a cascade skip reading an
     * untouched child table. Any doubt (no backing, stale, pre-v2) means
     * "cannot prove", and the caller falls through to the real read.
     */
    private function probeSaysNoReference(
        RelationSchema $relation,
        bool | float | int | string $value,
    ): bool {
        $entries = $this->trustedBackingEntries($relation, false);

        if ($entries === null) {
            return false;
        }

        [$index, $indexEntries] = $entries;

        return !$this->indexManager->eqExists($index, $indexEntries, $value);
    }

    /**
     * Resolves and reads the relation's backing index through the same
     * trust pipeline as the select path: the O(1) byteSize/format gate
     * first (stale -> null, silent degradation), then the full structural
     * validation (corruption -> INDEX_UNRELIABLE). $required marks the
     * restrict path, where a missing or non-covering backing index is a
     * configuration error instead of a null.
     *
     * @return null|array{IndexSchema, array<int,array{key:string,line:int}>}
     */
    private function trustedBackingEntries(
        RelationSchema $relation,
        bool $required,
    ): array | null {
        $child = $relation->childTable();
        $childSchema = $this->tableSchema($child);
        $index = null;

        if ($relation->backingIndex !== null) {
            foreach ($childSchema->indexes as $candidate) {
                if ($candidate->name === $relation->backingIndex) {
                    $index = $candidate;

                    break;
                }
            }
        }

        $covering = $index !== null
            && \count($index->fields) === 1
            && $index->fields[0]->field === $relation->childColumn();

        if (!$covering) {
            if ($required) {
                throw StorageException::fkBackingIndexMissing(
                    $child,
                    $relation->childColumn(),
                );
            }

            return null;
        }

        $memo = $this->ctxProbeEntries[$child][$index->name] ?? null;

        if ($memo === false) {
            return null;
        }

        if ($memo !== null) {
            return [$index, $memo];
        }

        $entries = null;

        if ($this->indexTrustworthy($childSchema)) {
            $entries = $this->indexManager->readIndexValidated(
                $child,
                $index,
                $this->meta->getLineCount($child),
                true,
            );
        }

        $this->ctxProbeEntries[$child][$index->name] = $entries ?? false;

        if ($entries === null) {
            return null;
        }

        return [$index, $entries];
    }

    /**
     * O(1) trust gate mirroring the select path: the backing index is
     * probed only when the committed byteSize matches the data file and
     * the key format is current (see JsonDataProvider::indexTrustworthy).
     */
    private function indexTrustworthy(TableSchema $tableSchema): bool
    {
        try {
            $byteSize = $this->meta->getByteSize($tableSchema->name);
            $format = $this->meta->getIndexFormat($tableSchema->name);
        } catch (StorageException) {
            return false;
        }

        if ($format < 2 || $byteSize === null) {
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

        return $byteSize === $this->ndjson->fileSizeBytes(
            $tableSchema->name,
            $tableSchema->getFileName(),
        );
    }

    /**
     * Collects the child rows cascade-deleted by one parent value and
     * returns them as new BFS queue entries (already marked deleted, so a
     * cyclic edge cannot re-expand them).
     *
     * @return array<int,array{string,int}>
     */
    private function cascadeDeleteChildren(
        RelationSchema $relation,
        bool | float | int | string $value,
    ): array {
        $child = $relation->childTable();

        if (
            !isset($this->ctxRecords[$child])
            && $this->probeSaysNoReference($relation, $value)
        ) {
            return [];
        }

        $this->loadTable($child);
        $fk = $relation->childColumn();
        $matched = $this->childIndexesByValue($child, $fk, $value);
        $entries = [];

        foreach ($matched as $index) {
            if (isset($this->ctxDeleted[$child][$index])) {
                continue;
            }

            $this->ctxDeleted[$child][$index] = true;
            $entries[] = [$child, $index];
        }

        return $entries;
    }

    /**
     * Applies the delete-walk SET_NULL patch to every matched LIVE child
     * row (a row deleted by the same statement disappears whole — its
     * value change is an onDelete event of its own edges, not an update)
     * and returns the value transition for the onUpdate closure over the
     * grandchildren.
     *
     * @return array<int,array{string,string,null|scalar,null|scalar}>
     */
    private function setNullChildren(
        RelationSchema $relation,
        bool | float | int | string $value,
    ): array {
        $child = $relation->childTable();

        if (
            !isset($this->ctxRecords[$child])
            && $this->probeSaysNoReference($relation, $value)
        ) {
            return [];
        }

        $this->loadTable($child);
        $fk = $relation->childColumn();
        $matched = $this->childIndexesByValue($child, $fk, $value);
        $patchedAny = false;

        foreach ($matched as $index) {
            if (isset($this->ctxDeleted[$child][$index])) {
                continue;
            }

            if (
                \array_key_exists(
                    $fk,
                    $this->ctxPatches[$child][$index] ?? [],
                )
            ) {
                continue;
            }

            $this->patchRow($child, $index, $fk, null);
            $patchedAny = true;
        }

        return $patchedAny ? [[$child, $fk, $value, null]] : [];
    }

    /**
     * Applies the encoded patch to every matched child row and returns at
     * most one propagation entry per (child, column, old, new) — the
     * grandchild walk needs the value transition, not each patched row.
     *
     * @return array<int,array{string,string,null|scalar,null|scalar}>
     */
    private function cascadeUpdateChildren(
        RelationSchema $relation,
        bool | float | int | string $oldValue,
        bool | float | int | string | null $newValue,
    ): array {
        $child = $relation->childTable();

        if (
            !isset($this->ctxRecords[$child])
            && $this->probeSaysNoReference($relation, $oldValue)
        ) {
            return [];
        }

        $this->loadTable($child);
        $fk = $relation->childColumn();
        $encoded = $this->encodeCascadePatchValue($relation, $newValue);
        $matched = $this->childIndexesByValue($child, $fk, $oldValue);
        $patchedAny = false;

        foreach ($matched as $index) {
            if (isset($this->ctxDeleted[$child][$index])) {
                continue;
            }

            if (
                \array_key_exists(
                    $fk,
                    $this->ctxPatches[$child][$index] ?? [],
                )
            ) {
                continue;
            }

            $this->patchRow($child, $index, $fk, $encoded);
            $patchedAny = true;
        }

        return $patchedAny ? [[$child, $fk, $oldValue, $encoded]] : [];
    }

    /**
     * @return array<int,array{string,string,null|scalar,null|scalar}>
     */
    private function setNullOnUpdate(
        RelationSchema $relation,
        bool | float | int | string $oldValue,
    ): array {
        $child = $relation->childTable();

        if (
            !isset($this->ctxRecords[$child])
            && $this->probeSaysNoReference($relation, $oldValue)
        ) {
            return [];
        }

        $this->loadTable($child);
        $fk = $relation->childColumn();
        $matched = $this->childIndexesByValue($child, $fk, $oldValue);
        $patchedAny = false;

        foreach ($matched as $index) {
            if (isset($this->ctxDeleted[$child][$index])) {
                continue;
            }

            if (
                \array_key_exists(
                    $fk,
                    $this->ctxPatches[$child][$index] ?? [],
                )
            ) {
                continue;
            }

            $this->patchRow($child, $index, $fk, null);
            $patchedAny = true;
        }

        return $patchedAny ? [[$child, $fk, $oldValue, null]] : [];
    }

    /**
     * Validates a cascade-on-update patch value against the child column
     * through the same encoder as insert/update (dv-cascade-validation-
     * point): TYPE_MISMATCH, NULL_NOT_ALLOWED, NON_FINITE_FLOAT and
     * INVALID_UTF8 fire before anything is written. Temporal columns are
     * the exception: the value is already the canonical stored UTC form
     * produced when the root patch was encoded (the executable-edge check
     * guarantees the base types match), and running it through the
     * encoder again would treat it as local time and shift it a second
     * time.
     */
    private function encodeCascadePatchValue(
        RelationSchema $relation,
        bool | float | int | string | null $value,
    ): bool | float | int | string | null {
        $childSchema = $this->tableSchema($relation->childTable());
        $column = $relation->childColumn();
        $info = ColumnTypeInfo::parse($childSchema->columns[$column]);

        if ($info->temporalKind() !== null) {
            if ($value === null && !$info->nullable) {
                throw StorageException::nullNotAllowed(
                    $childSchema->name,
                    $column,
                );
            }

            return $value;
        }

        $encoded = $this->values->encodeForWrite(
            $childSchema,
            [$column => $value],
            false,
        );

        return \array_key_exists($column, $encoded)
            ? $encoded[$column]
            : $value;
    }

    private function patchRow(
        string $table,
        int $index,
        string $column,
        bool | float | int | string | null $value,
    ): void {
        $this->ctxPatches[$table][$index][$column] = $value;
    }

    /**
     * Unique constraints of every table with patched rows, checked
     * against the FINAL state: each changed row's key must be unique
     * within the whole post-statement record set, so inter-row duplicates
     * of one batch are caught, while a pre-existing duplicate between two
     * UNTOUCHED rows does not block the statement. SQL NULL semantics via
     * keyOf (a null key never conflicts).
     */
    private function checkUniqueFinalState(): void
    {
        foreach ($this->ctxOrder as $table) {
            $patches = $this->ctxPatches[$table] ?? [];

            if ($patches === []) {
                continue;
            }

            $tableSchema = $this->ctxSchemas[$table];

            if ($tableSchema->uniqueConstraints === []) {
                continue;
            }

            $final = [];

            foreach ($this->ctxRecords[$table] as $index => $row) {
                $final[$index] = isset($patches[$index])
                    ? array_replace($row, $patches[$index])
                    : $row;
            }

            foreach ($tableSchema->uniqueConstraints as $constraint) {
                $keyCount = [];

                foreach ($final as $row) {
                    $key = $constraint->keyOf($row);

                    if ($key !== null) {
                        $keyCount[$key] = ($keyCount[$key] ?? 0) + 1;
                    }
                }

                foreach (array_keys($patches) as $index) {
                    $key = $constraint->keyOf($final[$index]);

                    if ($key === null || $keyCount[$key] < 2) {
                        continue;
                    }

                    $fieldValues = array_map(
                        static fn (string $f): string => (string)(
                            $final[$index][$f] ?? ''
                        ),
                        $constraint->fields,
                    );

                    throw StorageException::uniqueViolation(
                        $table,
                        implode(', ', $constraint->fields),
                        implode(', ', $fieldValues),
                    );
                }
            }
        }
    }

    /**
     * Builds the final write set: for every touched table the surviving
     * rows with patches applied, normalized against the schema and float-
     * widened; the write order is the reverse of the discovery order, so
     * the deepest children flip first and the root last.
     */
    private function assemblePlan(string $root, int $affected): FkWritePlan
    {
        $schemas = [];
        $tables = [];

        foreach ($this->ctxOrder as $table) {
            $deleted = $this->ctxDeleted[$table] ?? [];
            $patches = $this->ctxPatches[$table] ?? [];

            if (
                $deleted === []
                && $patches === []
                && !isset($this->ctxForceWrite[$table])
            ) {
                continue;
            }

            $tableSchema = $this->ctxSchemas[$table];
            $final = [];

            foreach ($this->ctxRecords[$table] as $index => $row) {
                if (isset($deleted[$index])) {
                    continue;
                }

                if (isset($patches[$index])) {
                    $row = array_replace($row, $patches[$index]);
                }

                $final[] = $this->normalizeRecord($tableSchema, $row);
            }

            $schemas[$table] = $tableSchema;
            $tables[$table] = $this->values->widenFloats(
                $tableSchema,
                $final,
            );
        }

        return new FkWritePlan(
            schemas: $schemas,
            tables: $tables,
            writeOrder: array_reverse(array_keys($tables)),
            rootTable: $root,
            affected: $affected,
        );
    }

    /**
     * Schema projection identical to the provider's normalizeRecord: only
     * declared columns, in declaration order, absent ones as null.
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
     * Returns the plan extended with SH locks for the parents of the
     * table's enforced relations (never downgrading an already planned
     * EX).
     *
     * @param array<string,string> $plan
     *
     * @return array<string,string>
     */
    private function addParentShLocks(string $tableName, array $plan): array
    {
        foreach ($this->schema->getRelations($tableName) as $relation) {
            if ($relation->childTable() !== $tableName) {
                continue;
            }

            $parent = $relation->parentTable();

            if ($parent === $tableName) {
                continue;
            }

            $enforced = $relation
                ->onDelete !== ForeignKeyActionEnum::NO_ACTION
                || $relation->onUpdate !== ForeignKeyActionEnum::NO_ACTION;

            if ($enforced) {
                $plan[$parent] ??= 'sh';
            }
        }

        return $plan;
    }
}
