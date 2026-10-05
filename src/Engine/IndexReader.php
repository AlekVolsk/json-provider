<?php

declare(strict_types=1);

namespace AV\JsonProvider\Engine;

use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Index\IndexEntryList;
use AV\JsonProvider\Index\IndexFileRegion;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Index\IndexSnapshot;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\DerivedFiles;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\TableLockManager;
use AV\JsonProvider\Validation\ValueValidator;

/**
 * The index path of select() and count(): a snapshot of the index and
 * the data file taken under the table SH lock and read after it is
 * released, the lookup of the matching lines, the share gate that
 * sends a wide lookup to a full scan, and the reading of the rows
 * found.
 *
 * @internal
 */
final class IndexReader
{
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

    private readonly DerivedFiles $derived;
    private readonly IndexManager $indexManager;
    private readonly TableLockManager $locks;
    private readonly NdjsonStorage $ndjson;
    private readonly SchemaRegistry $schema;
    private readonly ValueValidator $values;

    public function __construct(
        Context $context,
        private readonly RecordMatcher $matcher,
        private readonly TableStore $store,
        private readonly IndexPlanner $planner,
    ) {
        $this->derived = $context->derived;
        $this->indexManager = $context->indexManager;
        $this->locks = $context->locks;
        $this->ndjson = $context->ndjson;
        $this->schema = $context->schema;
        $this->values = $context->values;
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
    public function countViaIndex(
        string $tableName,
        array $conditions,
    ): int | null {
        if (
            $this->planner->resolveIndex(
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
                $index = $this->planner->resolveIndex(
                    $tableSchema,
                    [],
                    $conditions,
                );
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
    public function selectViaIndex(
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
     * What an index read needs from the table, taken under its SH lock to
     * read after the lock is released (IndexSnapshot): null when the index
     * is not trusted. A read in index order takes the index whole;
     * otherwise its sorted head is opened for searching in place, capped
     * by the share past which a full scan is cheaper (tooWide()), or the
     * index is read whole when no head is recorded.
     *
     * @param array<int,OrderBy> $ordering
     */
    public function openIndexSnapshot(
        IndexSchema $index,
        TableSchema $tableSchema,
        array $ordering,
    ): IndexSnapshot | null {
        $lineCount = $this->store->indexTrustedLineCount($tableSchema);

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
    public function dataSnapshot(
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
     * The data lines by number: through the line offsets when few lines are
     * wanted and the offsets describe the data file as it is, by walking
     * the file otherwise — one seek per line costs more than a walk once a
     * large share of the file is wanted.
     *
     * @param array<int,int> $lineNumbers
     *
     * @return array<int,array<string,null|scalar>>
     */
    public function readDataLines(
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
        $matches = $this->matcher->conditionFilter($conditions);
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
            $this->matcher->sortByOrdering($records, $ordering);
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
            && $this->planner->orderingIndexable($tableSchema, $ordering);
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
        $prefix = $this->planner->indexPrefix(
            $tableSchema,
            $index,
            $conditions,
        );

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
            if (
                !$this->planner->conditionIndexServable(
                    $tableSchema,
                    $condition,
                )
            ) {
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
        $matches = $this->matcher->conditionFilter($conditions);

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
}
