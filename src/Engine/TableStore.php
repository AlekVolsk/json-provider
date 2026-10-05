<?php

declare(strict_types=1);

namespace AV\JsonProvider\Engine;

use AV\JsonProvider\Cache\CacheInterface;
use AV\JsonProvider\Exception\JsonProviderDataException;
use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Exception\JsonProviderLockException;
use AV\JsonProvider\Exception\JsonProviderTableException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\ColumnDefaults;
use AV\JsonProvider\Schema\IdentifierRules;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Services\Format\FreshnessEnum;
use AV\JsonProvider\Services\Format\TableFreshness;
use AV\JsonProvider\Storage\BrokenRecordPolicyEnum;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\TableLockManager;
use AV\JsonProvider\Validation\ColumnTypeInfo;
use AV\JsonProvider\Validation\ValueValidator;
use Psr\Log\LoggerInterface;

/**
 * A table's state on disk as reads and writes see it: whether its
 * index files can be trusted, self-healing of a table that drifted
 * from its meta, the whole-table reads a write starts from and the
 * canonical rewrite, index appends, record normalization, unique
 * checks over a record set, and data cache entries keyed by the table
 * version.
 *
 * @internal
 */
final class TableStore
{
    private const string UNSTAMPED_NOTICE = 'table "%s" carries no '
        . 'generation-2 stamp (an older engine created or restored it): it '
        . 'is trusted by size alone, as under 1.0, until a full rewrite, a '
        . 'repair or a storage migration stamps it';

    private const string STALE_NOTICE = 'table "%s" was replaced after its '
        . 'last stamped commit (an older engine rewrote it, or a rewrite was '
        . 'interrupted): its indexes are not trusted until the next write, '
        . 'repair or storage migration re-verifies them';

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

    private readonly CacheInterface $cache;
    private readonly string $cacheNs;
    private readonly TableFreshness $freshness;
    private readonly IndexManager $indexManager;
    private readonly TableLockManager $locks;
    private readonly LoggerInterface | null $logger;
    private readonly MetaRegistry $meta;
    private readonly NdjsonStorage $ndjson;
    private readonly SchemaRegistry $schema;
    private readonly ValueValidator $values;

    public function __construct(
        private readonly Context $context,
    ) {
        $this->cache = $context->cache;
        $this->cacheNs = $context->cacheNs;
        $this->freshness = $context->freshness;
        $this->indexManager = $context->indexManager;
        $this->locks = $context->locks;
        $this->logger = $context->logger;
        $this->meta = $context->meta;
        $this->ndjson = $context->ndjson;
        $this->schema = $context->schema;
        $this->values = $context->values;
    }

    public function invalidateCache(string $tableName): void
    {
        $this->cache->invalidate($this->cacheKey(
            $tableName,
            $this->tableVersionTag($tableName),
        ));
    }

    /**
     * Creates an empty index file for every index in $desired that has none on
     * disk yet, so the subsequent rebuild (which replaces existing files under
     * flock) does not fail on a brand-new index.
     */
    public function createMissingIndexFiles(TableSchema $desired): void
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
    public function readAllForWrite(string $tableName): array
    {
        $tableSchema = $this->schema->getTable($tableName);
        $file = $tableSchema->getFileName();
        $policy = $this->context->brokenRecordPolicy;

        if ($policy === BrokenRecordPolicyEnum::Drop) {
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
     * Under BrokenRecordPolicyEnum::Refuse, fails a rewrite whose base read
     * skipped lines that are not records: writing that base would lose
     * them. Called after the read and before anything is written.
     */
    public function assertRewritable(string $tableName): void
    {
        $skipped = $this->skippedLines[$tableName] ?? null;

        if (
            $skipped === null
            || $this->context->brokenRecordPolicy
                !== BrokenRecordPolicyEnum::Refuse
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
    public function assertStoredColumnNames(array $records): void
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
    public function readAllRaw(string $tableName): array
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
    public function ensureTableConsistent(TableSchema $tableSchema): void
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
    public static function maxStoredId(array $records): int
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
    public function writeAll(
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
    public function publishCacheEntry(
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
     * O(1) read-side trust gate for the table's indexes, from one read of
     * meta.json: they are used only when the committed byteSize matches
     * the actual data file (the indexes describe exactly the committed
     * state) and the on-disk key format is current. Returns the committed
     * line count the indexes describe, or null on any doubt — missing
     * meta, unknown byteSize, foreign append, legacy format — which
     * degrades reads to a full scan; the next write heals and stamps under
     * the table EX lock.
     */
    public function indexTrustedLineCount(TableSchema $tableSchema): int | null
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
     * @param array<string,null|scalar> $record
     */
    public function appendIndexes(
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
     * Rejects a record set in which two records share a non-null key of the
     * constraint (SQL NULL semantics, type-strict keys — same rules as the
     * per-record check).
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    public function assertNoUniqueDuplicates(
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
    public function checkOneConstraint(
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
    public function normalizeRecord(
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
     * Builds the namespaced, version-tagged cache key of a table:
     * "jdp:<format>:<db-hash>:<table>:<tag>". The database hash isolates
     * databases sharing one backend pool; the tag binds the entry to one
     * committed state of the table, so a foreign or cross-process write
     * (which changes lineCount/byteSize) makes every stale entry
     * unreachable instead of served.
     */
    public function cacheKey(string $tableName, string $tag): string
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
    public function tableVersionTag(string $tableName): string
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
     * Rewrites sorted every index of the table whose appended tail grew
     * past $indexTailLimit entries.
     */
    public function mergeIndexTails(
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
            || $this->context->migrating
            || isset($this->context->freshnessNoted[$tableName])
        ) {
            return;
        }

        $this->context->freshnessNoted[$tableName] = true;
        $this->context->warn(\sprintf($notice, $tableName), false);
    }
}
