<?php

declare(strict_types=1);

namespace AV\JsonProvider\Engine;

use AV\JsonProvider\Cache\CacheInterface;
use AV\JsonProvider\Cache\NullCache;
use AV\JsonProvider\Exception\JsonProviderQueryException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Index\IndexSnapshot;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Format\TableFreshness;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\TableLockManager;
use AV\JsonProvider\Validation\ValueValidator;

/**
 * select(), count() and readAll(): the index path when an index serves
 * the query, otherwise a lock-free full scan with the data cache;
 * filtering, ordering, pagination and projection on the schema.
 *
 * @internal
 */
final class TableReader
{
    private const string CONTEXT_ORDER_BY = 'orderBy';

    private const string CONTEXT_DISTINCT = 'distinct';

    private readonly CacheInterface $cache;
    private readonly TableFreshness $freshness;
    private readonly TableLockManager $locks;
    private readonly NdjsonStorage $ndjson;
    private readonly SchemaRegistry $schema;
    private readonly ValueValidator $values;

    public function __construct(
        Context $context,
        private readonly RecordMatcher $matcher,
        private readonly TableStore $store,
        private readonly IndexPlanner $planner,
        private readonly IndexReader $indexReader,
    ) {
        $this->cache = $context->cache;
        $this->freshness = $context->freshness;
        $this->locks = $context->locks;
        $this->ndjson = $context->ndjson;
        $this->schema = $context->schema;
        $this->values = $context->values;
    }

    /**
     * @return array<int,array<string,null|scalar>>
     */
    public function readAll(string $tableName): array
    {
        $tableSchema = $this->schema->getTable($tableName);
        $records = $this->store->readAllRaw($tableName);
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
        $index = $this->planner->resolveIndex(
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
             * pushed into the index path, on any path the query takes.
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
                    $freshIndex = $this->planner->resolveIndex(
                        $freshSchema,
                        $ordering,
                        $conditions,
                        $pushPagination,
                    );
                    $snapshot = $freshIndex === null
                        ? null
                        : $this->indexReader->openIndexSnapshot(
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
                    && $this->planner->orderingIndexable(
                        $freshSchema,
                        $ordering,
                    )
                    && $distinctFields === [];
                $records = $this->indexReader->selectViaIndex(
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

        $cached = $this->cache->get($this->store->cacheKey(
            $tableName,
            $this->store->tableVersionTag($tableName),
        ));

        if ($cached !== null) {
            return $conditions === []
                ? \count($cached)
                : \count(array_filter(
                    $cached,
                    $this->matcher->conditionFilter($conditions),
                ));
        }

        if ($conditions !== []) {
            $viaIndex = $this->indexReader->countViaIndex(
                $tableName,
                $conditions,
            );

            if ($viaIndex !== null) {
                return $viaIndex;
            }
        }

        return $this->countScan($tableSchema, $conditions);
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
        $matches = $this->matcher->conditionFilter($conditions);
        $first = true;
        $records = $this->ndjson->records(
            $tableSchema->name,
            $tableSchema->getFileName(),
        );

        foreach ($records as $line => $record) {
            if ($first) {
                $this->store->assertStoredColumnNames([$record]);
                $first = false;
            }

            $record = $this->values->widenRecord($tableSchema, $record);

            if ($matches($record)) {
                yield $line => $record;
            }
        }
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
            $records = $this->store->readAllRaw($tableName);

            if ($conditions !== []) {
                $records = array_values(array_filter(
                    $records,
                    $this->matcher->conditionFilter($conditions),
                ));
            }
        }

        if ($ordering !== []) {
            $this->matcher->sortByOrdering($records, $ordering, $keep);
        }

        return $records;
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
}
