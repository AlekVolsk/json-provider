<?php

declare(strict_types=1);

namespace AV\JsonProvider\Engine;

use AV\JsonProvider\Exception\JsonProviderDataException;
use AV\JsonProvider\Exception\JsonProviderLockException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Index\IndexKey;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Relations\FkEngine;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Services\Format\TableFreshness;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\TableLockManager;
use AV\JsonProvider\Validation\ValueValidator;

/**
 * insert(), update(), delete(), truncate() and importRecords(): the
 * lock set of a mutation with the FK actions it carries, and unique
 * checks on insert through a matching index.
 *
 * @internal
 */
final class TableWriter
{
    private FkEngine | null $fkEngine = null;

    private readonly TableFreshness $freshness;
    private readonly IndexManager $indexManager;
    private readonly TableLockManager $locks;
    private readonly MetaRegistry $meta;
    private readonly NdjsonStorage $ndjson;
    private readonly SchemaRegistry $schema;
    private readonly ValueValidator $values;

    public function __construct(
        private readonly Context $context,
        private readonly RecordMatcher $matcher,
        private readonly TableStore $store,
        private readonly IndexReader $indexReader,
    ) {
        $this->freshness = $context->freshness;
        $this->indexManager = $context->indexManager;
        $this->locks = $context->locks;
        $this->meta = $context->meta;
        $this->ndjson = $context->ndjson;
        $this->schema = $context->schema;
        $this->values = $context->values;
    }

    /**
     * @param array<string,null|scalar> $record
     */
    public function insert(string $tableName, array $record): int
    {
        $this->context->assertWritable();

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
                $this->store->ensureTableConsistent($tableSchema);
                $this->checkUniqueOnInsert($tableSchema, $record);

                $probe = $this->store->normalizeRecord(
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
                                TableStore::maxStoredId(
                                    $this->store->readAllForWrite($tableName),
                                ),
                                $lastInserted,
                            ),
                        );
                    }
                }

                $id = $this->meta->allocateId($tableName);
                $record['id'] = $id;
                $lineNumber = $this->meta->getLineCount($tableName);
                $record = $this->store->normalizeRecord($tableSchema, $record);

                $byteSize = $this->ndjson->append(
                    $tableSchema->name,
                    $tableSchema->getFileName(),
                    $record,
                );
                $this->store->appendIndexes($tableSchema, $record, $lineNumber);
                $this->meta->commitAppend(
                    $tableName,
                    $lineNumber + 1,
                    $byteSize,
                );
                $this->store->mergeIndexTails($tableSchema, $lineNumber + 1);
                $this->store->invalidateCache($tableName);

                return $id;
            },
        );
    }

    /**
     * @param array<int,FilterCondition> $conditions
     * @param array<string,null|scalar>  $data
     */
    public function update(
        string $tableName,
        array $conditions,
        array $data,
    ): int {
        $this->context->assertWritable();

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
                $this->store->ensureTableConsistent($tableSchema);
                $records = $this->store->readAllForWrite($tableName);

                unset($data['id']);
                $data = $this->values->encodeForWrite(
                    $tableSchema,
                    $data,
                    false,
                );

                $targetIndexes = [];
                $matches = $this->matcher->conditionFilter($conditions);

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
     * @param array<int,FilterCondition> $conditions
     */
    public function delete(string $tableName, array $conditions): int
    {
        $this->context->assertWritable();

        return $this->withMutationLocks(
            $tableName,
            true,
            function (TableSchema $tableSchema) use ($conditions): int {
                $tableName = $tableSchema->name;
                $conditions = $this->values->encodeConditions(
                    $tableSchema,
                    $conditions,
                );
                $this->store->ensureTableConsistent($tableSchema);
                $records = $this->store->readAllForWrite($tableName);

                $deleteIndexes = [];
                $matches = $this->matcher->conditionFilter($conditions);

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

    public function truncate(string $tableName): void
    {
        $this->context->assertWritable();

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
                $this->store->invalidateCache($tableName);
                $this->store->ensureTableConsistent($tableSchema);
                $this->store->writeAll($tableName, $tableSchema, []);
                $this->meta->setLastInsertedId($tableName, 0);
            },
        );
    }

    /**
     * @param array<int,array<string,null|scalar>> $records
     */
    public function importRecords(string $tableName, array $records): int
    {
        $this->context->assertWritable();

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
                    $this->store->assertNoUniqueDuplicates(
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
                $this->store->invalidateCache($tableName);
                $this->store->ensureTableConsistent($tableSchema);
                $this->store->writeAll($tableName, $tableSchema, $prepared);
                $this->meta->setLastInsertedId(
                    $tableName,
                    max($watermark, $this->meta->getLastInsertedId($tableName)),
                );

                return \count($prepared);
            },
        );
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
                    $lineCount = $this->store->indexTrustedLineCount(
                        $tableSchema,
                    );
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
                $all ??= $this->store->readAllForWrite($tableSchema->name);
                $candidates = $all;
            }

            $this->store->checkOneConstraint(
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
        $snapshot = $this->indexReader->dataSnapshot(
            $tableSchema,
            $lineCount,
            $sorted,
            [],
        );
        $records = $this->values->widenFloats(
            $tableSchema,
            $this->indexReader->readDataLines($snapshot, $tableSchema, $lines),
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
                fn (TableSchema $t) => $this->store->ensureTableConsistent($t),
                fn (string $t): array => $this->store->readAllForWrite($t),
                fn (string $t) => $this->store->assertRewritable($t),
                fn (string $t, string $mode): bool => $this->locks->isHeld(
                    $t,
                    $mode,
                ),
                fn (string $t) => $this->store->invalidateCache($t),
                function (
                    string $t,
                    int $lineCount,
                    array $records,
                ): void {
                    $this->store->publishCacheEntry($t, $lineCount, $records);
                },
            );
        }

        return $this->fkEngine;
    }
}
