<?php

declare(strict_types=1);

namespace AV\JsonProvider\Index;

use AV\JsonProvider\Exception\JsonProviderSchemaException;
use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Exception\Locale\LocaleInterface;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\DerivedFiles;
use AV\JsonProvider\Storage\NdjsonStorage;

/**
 * Manages index files: rebuilds them on write, looks up line numbers on read.
 *
 * Indexes are NDJSON files, kept in the same per-table subdirectory:
 * dbPath/<tableName>/<index-file>. Physical I/O is delegated to NdjsonStorage —
 * IndexManager has no knowledge of the DB location.
 *
 * Index entry format: {"key":"encoded_key","line":N} with v2 keys (IndexKey).
 * Entries are sorted by key (strcmp), enabling binary range lookups; a fresh
 * rebuild writes the file sorted, appends may leave an unsorted tail which
 * readIndexValidated() detects and re-sorts in memory.
 *
 * Read path contract: consumers obtain entries via readIndexValidated()
 * (structural validation, throws INDEX_UNRELIABLE in strict mode) and pass
 * them into searchLines()/eqExists(). Every search compares only the FIRST
 * index component: the part encoding is prefix-free, so "first part equals
 * the target" is exactly str_starts_with on the full key.
 */
final class IndexManager
{
    public function __construct(
        private readonly NdjsonStorage $storage,
        private readonly DerivedFiles | null $derived = null,
    ) {
    }

    /**
     * Rebuilds all indexes for the table after a write.
     *
     * @param array<int,array<string,null|scalar>> $records current records
     *                                                      (post-write)
     */
    public function rebuild(TableSchema $tableSchema, array $records): void
    {
        foreach ($tableSchema->indexes as $index) {
            $this->rebuildOne($tableSchema->name, $index, $records);
        }
    }

    /**
     * Looks up an index in the table schema by name.
     * Throws IndexNotFound if no index matches.
     */
    public function findIndex(
        TableSchema $tableSchema,
        string $indexName,
    ): IndexSchema {
        foreach ($tableSchema->indexes as $index) {
            if ($index->name === $indexName) {
                return $index;
            }
        }

        throw new JsonProviderSchemaException(
            JsonProviderErrorEn::IndexNotFound,
            $tableSchema->name,
            $indexName,
        );
    }

    /**
     * Appends an entry into every index of the table.
     *
     * @param array<string,null|scalar> $record
     */
    public function appendRecord(
        TableSchema $tableSchema,
        array $record,
        int $lineNumber,
    ): void {
        foreach ($tableSchema->indexes as $index) {
            $key = IndexKey::build($record, $index);
            $entry = ['key' => $key, 'line' => $lineNumber];

            $this->storage->append(
                $tableSchema->name,
                $index->getFileName(),
                $entry,
            );
        }
    }

    /**
     * Reads an index file and returns all {key, line} pairs sorted by key.
     * Legacy lenient reader: malformed rows are skipped, no structural
     * validation. Query paths use readIndexValidated() instead.
     *
     * @return array<int,array{key:string,line:int}>
     */
    public function readIndex(string $tableName, IndexSchema $index): array
    {
        $rows = $this->storage->read($tableName, $index->getFileName());
        $result = [];

        foreach ($rows as $row) {
            $key = $row['key'] ?? null;
            $line = $row['line'] ?? null;

            if (\is_string($key) && \is_int($line)) {
                $result[] = ['key' => $key, 'line' => $line];
            }
        }

        usort(
            $result,
            static fn (array $a, array $b): int => strcmp($a['key'], $b['key']),
        );

        return $result;
    }

    /**
     * Reads an index file with full structural validation, returning entries
     * sorted by key. One O(n) pass checks everything at once:
     *
     *  - the file exists and every row is a {key: string, line: int} pair;
     *  - the entry count equals the committed lineCount;
     *  - the lines form a permutation of [0, lineCount) — no dangling
     *    references, no duplicates, no gaps;
     *  - every key is a well-formed v2 key for this index's field list;
     *  - sortedness is tracked in the same pass — a sorted file (fresh
     *    rebuild) skips the usort; an appended tail triggers it.
     *
     * A violation throws INDEX_UNRELIABLE when $strict (v2 index — the
     * structure is guaranteed, corruption must be loud), or returns null
     * (degrade to full scan) for pre-v2 files.
     *
     * The permutation bitmap and the per-key decode cost roughly a quarter of
     * a lookup, and skipping them on the query path was measured and then
     * rejected: IndexTrustTest holds that a duplicated line reference or a
     * malformed key must raise INDEX_UNRELIABLE from the QUERY that meets it,
     * not merely from a later rebuild or validate(). Detection at the point of
     * use is the contract; the pass stays.
     *
     * @return null|array<int,array{key:string,line:int}>
     */
    public function readIndexValidated(
        string $tableName,
        IndexSchema $index,
        int $expectedLineCount,
        bool $strict,
    ): array | null {
        $fail = static function (
            LocaleInterface $reason,
            string ...$details,
        ) use (
            $tableName,
            $index,
            $strict
        ): null {
            if ($strict) {
                throw new JsonProviderServiceException(
                    $reason,
                    $index->name,
                    $tableName,
                    ...$details,
                );
            }

            return null;
        };

        if (!$this->storage->exists($tableName, $index->getFileName())) {
            return $fail(JsonProviderErrorEn::IndexFileMissing);
        }

        $rows = $this->storage->read($tableName, $index->getFileName());

        if (\count($rows) !== $expectedLineCount) {
            return $fail(
                JsonProviderErrorEn::IndexCountMismatch,
                (string)\count($rows),
                (string)$expectedLineCount,
            );
        }

        $entries = [];
        $covered = array_fill(0, max(0, $expectedLineCount), false);
        $sorted = true;
        $prevKey = null;

        foreach ($rows as $row) {
            $key = $row['key'] ?? null;
            $line = $row['line'] ?? null;

            if (!\is_string($key) || !\is_int($line)) {
                return $fail(JsonProviderErrorEn::IndexEntryMalformed);
            }

            if ($line < 0 || $line >= $expectedLineCount) {
                return $fail(JsonProviderErrorEn::IndexBrokenPermutation);
            }

            if ($covered[$line] === true) {
                return $fail(JsonProviderErrorEn::IndexBrokenPermutation);
            }

            $covered[$line] = true;

            if (!IndexKey::wellFormed($key, $index)) {
                return $fail(JsonProviderErrorEn::IndexKeyMalformed);
            }

            if ($prevKey !== null && strcmp($prevKey, $key) > 0) {
                $sorted = false;
            }

            $prevKey = $key;
            $entries[] = ['key' => $key, 'line' => $line];
        }

        if (!$sorted) {
            usort(
                $entries,
                static fn (array $a, array $b): int => strcmp(
                    $a['key'],
                    $b['key'],
                ),
            );
        }

        return $entries;
    }

    /**
     * Returns main-file line numbers that satisfy the condition via the
     * index, searching over pre-validated sorted entries. Returns null if
     * the index cannot answer the condition (LIKE, NOT, another field,
     * malformed bounds) — the caller degrades to a full scan. Returns an
     * empty array as an authoritative "no rows match".
     *
     * Only the first index component is compared; extra components of a
     * composite key never leak into the comparison thanks to the
     * prefix-free part encoding. All lookups are binary searches over the
     * sorted entries: O(log n + k).
     *
     * @param array<int,array{key:string,line:int}> $entries
     *
     * @return null|array<int,int>
     */
    public function searchLines(
        TableSchema $tableSchema,
        array $entries,
        IndexSchema $index,
        FilterCondition $condition,
    ): array | null {
        return $this->searchLinesIn(
            new IndexEntryList($entries),
            $index,
            $condition,
        );
    }

    /**
     * searchLines() over any sorted entries — in memory or the sorted head
     * of an index file.
     *
     * @return null|array<int,int>
     */
    public function searchLinesIn(
        SortedIndexEntries $entries,
        IndexSchema $index,
        FilterCondition $condition,
    ): array | null {
        if ($condition->not) {
            return null;
        }

        $firstField = $index->fields[0] ?? null;

        if ($firstField === null || $condition->field !== $firstField->field) {
            return null;
        }

        if ($entries->start() === $entries->end()) {
            return [];
        }

        if (self::holdsNonFiniteFloat($condition->value)) {
            return null;
        }

        $raw = $condition->value;
        $value = \is_scalar($raw) || $raw === null ? $raw : null;

        return match ($condition->operator) {
            FilterOperatorEnum::EQ => $this->searchExact(
                $entries,
                $firstField,
                $value,
            ),
            FilterOperatorEnum::GT => $this->searchRange(
                $entries,
                $firstField,
                $value,
                null,
                false,
                false,
            ),
            FilterOperatorEnum::GTE => $this->searchRange(
                $entries,
                $firstField,
                $value,
                null,
                true,
                false,
            ),
            FilterOperatorEnum::LT => $this->searchRange(
                $entries,
                $firstField,
                null,
                $value,
                false,
                false,
            ),
            FilterOperatorEnum::LTE => $this->searchRange(
                $entries,
                $firstField,
                null,
                $value,
                false,
                true,
            ),
            FilterOperatorEnum::BETWEEN => $this->searchBetween(
                $entries,
                $firstField,
                $condition->value,
            ),
            FilterOperatorEnum::LIKE => null,
            FilterOperatorEnum::IN   => $this->searchIn(
                $entries,
                $firstField,
                $condition->value,
            ),
        };
    }

    /**
     * EQ existence probe over pre-validated entries — the shared primitive
     * for FK backing-index checks: they must see exactly what the select
     * path sees, including INDEX_UNRELIABLE on structural corruption
     * (raised earlier by readIndexValidated).
     *
     * @param array<int,array{key:string,line:int}> $entries
     */
    public function eqExists(
        IndexSchema $index,
        array $entries,
        bool | float | int | string | null $value,
    ): bool {
        $firstField = $index->fields[0] ?? null;

        if ($firstField === null || $entries === []) {
            return false;
        }

        $target = IndexKey::buildFromValue($value, $firstField);
        $list = new IndexEntryList($entries);

        return $list->lowerBound($target) < $list->upperBound($target);
    }

    /**
     * Rebuilds a single index file from scratch using the given records.
     * The file is written sorted by key, so validated readers skip the
     * in-memory sort. Atomic at the file level (tmp+fsync+rename).
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    public function rebuildOne(
        string $tableName,
        IndexSchema $index,
        array $records,
    ): void {
        $entries = [];

        foreach ($records as $line => $record) {
            $entries[] = [
                'key'  => IndexKey::build($record, $index),
                'line' => $line,
            ];
        }

        usort(
            $entries,
            static fn (array $a, array $b): int => strcmp($a['key'], $b['key']),
        );

        $this->writeSorted($tableName, $index, $entries);
    }

    /**
     * The index searchable in place: the sorted head of its file and the
     * appended tail read into memory. Null when no valid boundary is
     * recorded for the file as it is now — the caller then reads the whole
     * file. Tail entries are checked like every entry read, and head and
     * tail together must hold exactly $lineCount entries; a violation
     * raises INDEX_UNRELIABLE.
     *
     * @return null|array{IndexFileRegion, IndexEntryList}
     */
    public function openSorted(
        string $tableName,
        IndexSchema $index,
        int $lineCount,
    ): array | null {
        if ($this->derived === null) {
            return null;
        }

        $path = $this->storage->pathOf($tableName, $index->getFileName());
        $boundary = $this->derived->indexBoundary(
            $tableName,
            $index->getFileName(),
            $path,
        );

        if ($boundary === null) {
            return null;
        }

        $fail = static function (
            LocaleInterface $reason,
            string ...$details,
        ) use (
            $tableName,
            $index,
        ): never {
            throw new JsonProviderServiceException(
                $reason,
                $index->name,
                $tableName,
                ...$details,
            );
        };
        $tail = self::readTail(
            $path,
            $boundary['bytes'],
            $index,
            $lineCount,
            $fail,
        );

        if ($boundary['count'] + \count($tail) !== $lineCount) {
            $fail(
                JsonProviderErrorEn::IndexCountMismatch,
                (string)($boundary['count'] + \count($tail)),
                (string)$lineCount,
            );
        }

        return [
            new IndexFileRegion(
                $path,
                $boundary['bytes'],
                $index,
                $lineCount,
                $fail,
                $boundary['count'],
            ),
            new IndexEntryList($tail),
        ];
    }

    /**
     * searchLines() over an index opened with openSorted(): head and tail
     * are searched apart and their lines merged. Null when the index cannot
     * answer the condition. A data line found twice breaks the permutation
     * and raises INDEX_UNRELIABLE.
     *
     * @param array{IndexFileRegion, IndexEntryList} $sorted
     *
     * @return null|array<int,int>
     */
    public function searchSorted(
        string $tableName,
        array $sorted,
        IndexSchema $index,
        FilterCondition $condition,
    ): array | null {
        [$head, $tail] = $sorted;
        $headLines = $this->searchLinesIn($head, $index, $condition);
        $tailLines = $this->searchLinesIn($tail, $index, $condition);

        if ($headLines === null || $tailLines === null) {
            return null;
        }

        return self::mergeSorted($tableName, $index, $headLines, $tailLines);
    }

    /**
     * The lines whose entry holds exactly $key, a full key of the index,
     * over an index opened with openSorted(). Every key of one index has
     * the same prefix-free parts, so no full key is a prefix of another
     * and the bounds of $key enclose exactly the entries equal to it.
     *
     * @param array{IndexFileRegion, IndexEntryList} $sorted
     *
     * @return array<int,int>
     */
    public function searchSortedKey(
        string $tableName,
        array $sorted,
        IndexSchema $index,
        string $key,
    ): array {
        [$head, $tail] = $sorted;

        return self::mergeSorted(
            $tableName,
            $index,
            $head->lines($head->lowerBound($key), $head->upperBound($key)),
            $tail->lines($tail->lowerBound($key), $tail->upperBound($key)),
        );
    }

    /**
     * The lines whose entries start with $values — the values of the
     * index's leading fields, in index order — narrowed by $range, a range
     * condition on the field right after them. A superset of the matching
     * rows, like every lookup: the caller filters the rows it reads. A
     * range the index cannot encode leaves the prefix alone to narrow.
     *
     * @param array<int,null|bool|float|int|string> $values
     *
     * @return array<int,int>
     */
    public function searchPrefixIn(
        SortedIndexEntries $entries,
        IndexSchema $index,
        array $values,
        FilterCondition | null $range,
    ): array {
        $prefix = '';
        $values = array_values($values);

        foreach ($values as $i => $value) {
            $field = $index->fields[$i] ?? null;

            if ($field === null) {
                break;
            }

            $prefix .= IndexKey::buildFromValue($value, $field);
        }

        $next = $index->fields[\count($values)] ?? null;
        $lines = $range !== null && $next !== null
            ? $this->searchRangeAfter($entries, $next, $range, $prefix)
            : null;

        return $lines ?? $entries->lines(
            $entries->lowerBound($prefix),
            $entries->upperBound($prefix),
        );
    }

    /**
     * searchPrefixIn() over an index opened with openSorted(): head and
     * tail are searched apart and their lines merged.
     *
     * @param array{IndexFileRegion, IndexEntryList} $sorted
     * @param array<int,null|bool|float|int|string>  $values
     *
     * @return array<int,int>
     */
    public function searchSortedPrefix(
        string $tableName,
        array $sorted,
        IndexSchema $index,
        array $values,
        FilterCondition | null $range,
    ): array {
        [$head, $tail] = $sorted;

        return self::mergeSorted(
            $tableName,
            $index,
            $this->searchPrefixIn($head, $index, $values, $range),
            $this->searchPrefixIn($tail, $index, $values, $range),
        );
    }

    /**
     * Checks the records read for lines a searchSorted() returned against
     * the entries that pointed at them: every line must yield a record, and
     * the key built from the record must be the entry's key (see
     * RecordVerifier). A mismatch
     * means the index no longer describes the data and raises
     * INDEX_UNRELIABLE — the lookup would otherwise return wrong rows.
     *
     * @param array{IndexFileRegion, IndexEntryList} $sorted
     * @param array<int,int>                         $lines
     * @param array<int,array<string,null|scalar>>   $records
     */
    public function verifyRecords(
        string $tableName,
        array $sorted,
        IndexSchema $index,
        array $lines,
        array $records,
    ): void {
        if (\count($records) !== \count($lines)) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::IndexLinesMissing,
                $index->name,
                $tableName,
            );
        }

        $verify = $this->recordVerifier($tableName, $sorted, $index);

        foreach (array_values($lines) as $i => $line) {
            $verify->check($line, $records[$i]);
        }
    }

    /**
     * verifyRecords() one record at a time, for a caller that reads the
     * records as a stream. Checking that every line yielded a record is the
     * caller's.
     *
     * @param array{IndexFileRegion, IndexEntryList} $sorted
     */
    public function recordVerifier(
        string $tableName,
        array $sorted,
        IndexSchema $index,
    ): RecordVerifier {
        return new RecordVerifier($tableName, $index, $sorted[0], $sorted[1]);
    }

    /**
     * Rewrites the index file sorted once its unsorted tail holds more than
     * $limit entries, or when no boundary is recorded for it yet, so a
     * lookup keeps reading O(log n) lines. Runs after an append, under the
     * table EX lock; a file that fails the structural check is left as it
     * is — the append already succeeded, and the lookup that meets the
     * damage reports it.
     */
    public function mergeTail(
        string $tableName,
        IndexSchema $index,
        int $lineCount,
        int $limit,
    ): void {
        if ($this->derived === null || !$this->derived->enabled()) {
            return;
        }

        $boundary = $this->derived->indexBoundary(
            $tableName,
            $index->getFileName(),
            $this->storage->pathOf($tableName, $index->getFileName()),
        );

        if ($boundary !== null && $lineCount - $boundary['count'] <= $limit) {
            return;
        }

        $entries = $this->readIndexValidated(
            $tableName,
            $index,
            $lineCount,
            false,
        );

        if ($entries !== null) {
            $this->writeSorted($tableName, $index, $entries);
        }
    }

    /**
     * Writes sorted entries as the whole index file and records them as
     * its sorted head.
     *
     * @param array<int,array{key:string,line:int}> $entries
     */
    private function writeSorted(
        string $tableName,
        IndexSchema $index,
        array $entries,
    ): void {
        $bytes = $this->storage->write(
            $tableName,
            $index->getFileName(),
            $entries,
        );
        $this->derived?->setIndexBoundary(
            $tableName,
            $index->getFileName(),
            $this->storage->pathOf($tableName, $index->getFileName()),
            $bytes,
            \count($entries),
        );
    }

    /**
     * The head and tail lines of one lookup merged; a data line found
     * twice breaks the permutation and raises INDEX_UNRELIABLE.
     *
     * @param array<int,int> $headLines
     * @param array<int,int> $tailLines
     *
     * @return array<int,int>
     */
    private static function mergeSorted(
        string $tableName,
        IndexSchema $index,
        array $headLines,
        array $tailLines,
    ): array {
        $lines = array_merge($headLines, $tailLines);

        if (\count(array_flip($lines)) !== \count($lines)) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::IndexBrokenPermutation,
                $index->name,
                $tableName,
            );
        }

        return $lines;
    }

    /**
     * The checked entries after the sorted head, sorted by key.
     *
     * @param \Closure(LocaleInterface, string...): never $fail
     *
     * @return array<int,array{key:string,line:int}>
     */
    private static function readTail(
        string $path,
        int $from,
        IndexSchema $index,
        int $lineCount,
        \Closure $fail,
    ): array {
        $handle = is_file($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            $fail(JsonProviderErrorEn::IndexFileMissing);
        }

        $tail = [];

        try {
            fseek($handle, $from);

            while (($raw = fgets($handle)) !== false) {
                $tail[] = IndexFileRegion::parse(
                    $raw,
                    $index,
                    $lineCount,
                    $fail,
                );
            }
        } finally {
            fclose($handle);
        }

        usort(
            $tail,
            static fn (array $a, array $b): int => strcmp($a['key'], $b['key']),
        );

        return $tail;
    }

    /**
     * @return array<int,int>
     */
    private function searchExact(
        SortedIndexEntries $entries,
        IndexFieldSchema $fieldSchema,
        bool | float | int | string | null $value,
    ): array {
        $target = IndexKey::buildFromValue($value, $fieldSchema);

        return $entries->lines(
            $entries->lowerBound($target),
            $entries->upperBound($target),
        );
    }

    /**
     * IN: one lookup per distinct value in key order, each starting where
     * the previous run ended; adjacent runs are read as one.
     *
     * @return null|array<int,int>
     */
    private function searchIn(
        SortedIndexEntries $entries,
        IndexFieldSchema $fieldSchema,
        mixed $values,
    ): array | null {
        if (!\is_array($values)) {
            return null;
        }

        if ($values === []) {
            return [];
        }

        $targets = [];

        foreach ($values as $v) {
            if (\is_scalar($v) || $v === null) {
                $targets[IndexKey::buildFromValue($v, $fieldSchema)] = true;
            }
        }

        $keys = array_map(strval(...), array_keys($targets));
        sort($keys, SORT_STRING);
        $runs = [];
        $from = $entries->start();

        foreach ($keys as $target) {
            $lo = $entries->lowerBound($target, $from);
            $from = $entries->upperBound($target, $lo);

            if ($lo === $from) {
                continue;
            }

            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][1] === $lo) {
                $runs[$last][1] = $from;
            } else {
                $runs[] = [$lo, $from];
            }
        }

        $lines = [];

        foreach ($runs as [$lo, $hi]) {
            array_push($lines, ...$entries->lines($lo, $hi));
        }

        return $lines;
    }

    /**
     * A range condition on one component within the entries that start
     * with $prefix; null when the index cannot encode it.
     *
     * @return null|array<int,int>
     */
    private function searchRangeAfter(
        SortedIndexEntries $entries,
        IndexFieldSchema $field,
        FilterCondition $range,
        string $prefix,
    ): array | null {
        if ($range->not || self::holdsNonFiniteFloat($range->value)) {
            return null;
        }

        $raw = $range->value;
        $value = \is_scalar($raw) ? $raw : null;

        if ($range->operator === FilterOperatorEnum::BETWEEN) {
            return $this->searchBetween($entries, $field, $raw, $prefix);
        }

        if ($value === null) {
            return null;
        }

        return match ($range->operator) {
            FilterOperatorEnum::GT => $this->searchRange(
                $entries,
                $field,
                $value,
                null,
                false,
                false,
                $prefix,
            ),
            FilterOperatorEnum::GTE => $this->searchRange(
                $entries,
                $field,
                $value,
                null,
                true,
                false,
                $prefix,
            ),
            FilterOperatorEnum::LT => $this->searchRange(
                $entries,
                $field,
                null,
                $value,
                false,
                false,
                $prefix,
            ),
            FilterOperatorEnum::LTE => $this->searchRange(
                $entries,
                $field,
                null,
                $value,
                false,
                true,
                $prefix,
            ),
            default => null,
        };
    }

    /**
     * Range search over one component: from $from to $to (both bounds
     * optional), within the entries that start with $prefix — the encoded
     * values of the components before it, empty for the first one. On a
     * DESC field the logical bounds are mirrored BEFORE key encoding: the
     * encoded order is inverted, so "value >= from" becomes
     * "key <= key(from)" — swapping the bounds and their inclusivity maps
     * the logical range onto the physical key order.
     *
     * Numeric bounds select a SUPERSET: the bound is the tag+double key
     * prefix (no residual) and the whole equal-double run is included
     * regardless of inclusivity — the residual orders ints beyond 2^53
     * more finely than the engine's `<=>`, so a full-key exact bound
     * could silently drop rows the comparator keeps. The range paths are
     * always post-filtered with the exact operator, which trims the
     * superset back precisely. Non-numeric bounds (strings, bools) have
     * exact keys that agree with the comparator, so they keep the exact
     * inclusive/exclusive boundary.
     *
     * @return array<int,int>
     */
    private function searchRange(
        SortedIndexEntries $entries,
        IndexFieldSchema $fieldSchema,
        bool | float | int | string | null $from,
        bool | float | int | string | null $to,
        bool $fromInclusive,
        bool $toInclusive,
        string $prefix = '',
    ): array {
        if ($fieldSchema->direction === SortDirectionEnum::DESC) {
            [$from, $to] = [$to, $from];
            [$fromInclusive, $toInclusive] = [$toInclusive, $fromInclusive];
        }

        $start = $prefix === ''
            ? $entries->start()
            : $entries->lowerBound($prefix);
        $end = $prefix === ''
            ? $entries->end()
            : $entries->upperBound($prefix);

        if ($from !== null) {
            if (\is_int($from) || \is_float($from)) {
                $start = $entries->lowerBound(
                    $prefix . IndexKey::numberBoundPrefix($from, $fieldSchema),
                );
            } else {
                $fromKey = $prefix
                    . IndexKey::buildFromValue($from, $fieldSchema);
                $start = $fromInclusive
                    ? $entries->lowerBound($fromKey)
                    : $entries->upperBound($fromKey);
            }
        }

        if ($to !== null) {
            if (\is_int($to) || \is_float($to)) {
                $end = $entries->upperBound(
                    $prefix . IndexKey::numberBoundPrefix($to, $fieldSchema),
                );
            } else {
                $toKey = $prefix . IndexKey::buildFromValue($to, $fieldSchema);
                $end = $toInclusive
                    ? $entries->upperBound($toKey)
                    : $entries->lowerBound($toKey);
            }
        }

        return $start < $end ? $entries->lines($start, $end) : [];
    }

    /**
     * Whether the condition value (scalar or array of bounds/candidates)
     * holds a non-finite float — such a condition cannot be encoded into
     * an index key and degrades to a full scan instead of throwing.
     */
    private static function holdsNonFiniteFloat(mixed $value): bool
    {
        if (\is_float($value) && !is_finite($value)) {
            return true;
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                if (\is_float($item) && !is_finite($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return null|array<int,int>
     */
    private function searchBetween(
        SortedIndexEntries $entries,
        IndexFieldSchema $fieldSchema,
        mixed $value,
        string $prefix = '',
    ): array | null {
        if (
            !\is_array($value)
            || !\array_key_exists(0, $value)
            || !\array_key_exists(1, $value)
        ) {
            return null;
        }

        if (
            (!\is_scalar($value[0]) && $value[0] !== null)
            || (!\is_scalar($value[1]) && $value[1] !== null)
        ) {
            return null;
        }

        if ($value[0] === null || $value[1] === null) {
            return null;
        }

        return $this->searchRange(
            $entries,
            $fieldSchema,
            $value[0],
            $value[1],
            true,
            true,
            $prefix,
        );
    }
}
