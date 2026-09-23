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

            if (!$this->keyWellFormed($key, $index)) {
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
        if ($condition->not) {
            return null;
        }

        $firstField = $index->fields[0] ?? null;

        if ($firstField === null || $condition->field !== $firstField->field) {
            return null;
        }

        if ($entries === []) {
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

        return self::lowerBound($entries, $target)
            < self::upperBound($entries, $target);
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

        $this->storage->write($tableName, $index->getFileName(), $entries);
    }

    /**
     * Validates the structural shape of one v2 key against the index's
     * field list: every part parses with its type tag, string parts
     * terminate, escapes are complete, and no bytes trail the last part.
     */
    private function keyWellFormed(string $key, IndexSchema $index): bool
    {
        if (
            \strlen($key) % 2 !== 0
            || preg_match('/^[0-9a-f]*$/D', $key) !== 1
        ) {
            return false;
        }

        $binary = hex2bin($key);

        if ($binary === false) {
            return false;
        }

        $pos = 0;
        $len = \strlen($binary);

        foreach ($index->fields as $fieldSchema) {
            $desc = $fieldSchema->direction === SortDirectionEnum::DESC;

            if ($pos >= $len) {
                return false;
            }

            $tag = \ord($binary[$pos]);

            if ($desc) {
                $tag = 255 - $tag;
            }

            $pos++;

            if ($tag <= 0x02) {
                continue;
            }

            if ($tag === 0x03) {
                $pos += 16;

                if ($pos > $len) {
                    return false;
                }

                continue;
            }

            if ($tag !== 0x04) {
                return false;
            }

            $terminator = $desc ? 0xFF : 0x00;
            $escape = $desc ? 0xFE : 0x01;
            $terminated = false;

            while ($pos < $len) {
                $byte = \ord($binary[$pos]);
                $pos++;

                if ($byte === $terminator) {
                    $terminated = true;

                    break;
                }

                if ($byte === $escape) {
                    if ($pos >= $len) {
                        return false;
                    }

                    $next = \ord($binary[$pos]);
                    $decoded = $desc ? 255 - $next : $next;

                    if ($decoded !== 0x01 && $decoded !== 0x02) {
                        return false;
                    }

                    $pos++;
                }
            }

            if (!$terminated) {
                return false;
            }
        }

        return $pos === $len;
    }

    /**
     * @param array<int,array{key:string,line:int}> $entries
     *
     * @return array<int,int>
     */
    private function searchExact(
        array $entries,
        IndexFieldSchema $fieldSchema,
        bool | float | int | string | null $value,
    ): array {
        $target = IndexKey::buildFromValue($value, $fieldSchema);

        return $this->sliceLines(
            $entries,
            self::lowerBound($entries, $target),
            self::upperBound($entries, $target),
        );
    }

    /**
     * IN: one binary lookup per distinct value, results merged in entry
     * order per value.
     *
     * @param array<int,array{key:string,line:int}> $entries
     *
     * @return null|array<int,int>
     */
    private function searchIn(
        array $entries,
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

        $lines = [];

        foreach (array_keys($targets) as $target) {
            $lo = self::lowerBound($entries, $target);
            $hi = self::upperBound($entries, $target);

            for ($i = $lo; $i < $hi; $i++) {
                $lines[] = $entries[$i]['line'];
            }
        }

        return $lines;
    }

    /**
     * Range search over the first component: from $from to $to (both bounds
     * optional). On a DESC field the logical bounds are mirrored BEFORE key
     * encoding: the encoded order is inverted, so "value >= from" becomes
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
     * @param array<int,array{key:string,line:int}> $entries
     *
     * @return array<int,int>
     */
    private function searchRange(
        array $entries,
        IndexFieldSchema $fieldSchema,
        bool | float | int | string | null $from,
        bool | float | int | string | null $to,
        bool $fromInclusive,
        bool $toInclusive,
    ): array {
        if ($fieldSchema->direction === SortDirectionEnum::DESC) {
            [$from, $to] = [$to, $from];
            [$fromInclusive, $toInclusive] = [$toInclusive, $fromInclusive];
        }

        $start = 0;
        $end = \count($entries);

        if ($from !== null) {
            if (\is_int($from) || \is_float($from)) {
                $start = self::lowerBound(
                    $entries,
                    IndexKey::numberBoundPrefix($from, $fieldSchema),
                );
            } else {
                $fromKey = IndexKey::buildFromValue($from, $fieldSchema);
                $start = $fromInclusive
                    ? self::lowerBound($entries, $fromKey)
                    : self::upperBound($entries, $fromKey);
            }
        }

        if ($to !== null) {
            if (\is_int($to) || \is_float($to)) {
                $end = self::upperBound(
                    $entries,
                    IndexKey::numberBoundPrefix($to, $fieldSchema),
                );
            } else {
                $toKey = IndexKey::buildFromValue($to, $fieldSchema);
                $end = $toInclusive
                    ? self::upperBound($entries, $toKey)
                    : self::lowerBound($entries, $toKey);
            }
        }

        return $this->sliceLines($entries, $start, $end);
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
     * @param array<int,array{key:string,line:int}> $entries
     *
     * @return null|array<int,int>
     */
    private function searchBetween(
        array $entries,
        IndexFieldSchema $fieldSchema,
        mixed $value,
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
        );
    }

    /**
     * @param array<int,array{key:string,line:int}> $entries
     *
     * @return array<int,int>
     */
    private function sliceLines(array $entries, int $start, int $end): array
    {
        $lines = [];

        for ($i = $start; $i < $end; $i++) {
            $lines[] = $entries[$i]['line'];
        }

        return $lines;
    }

    /**
     * First entry whose key is >= the part key. Any key whose first part
     * equals the target starts with it and therefore compares >= to it, so
     * this is the start of the equal-first-part run.
     *
     * @param array<int,array{key:string,line:int}> $entries
     */
    private static function lowerBound(array $entries, string $partKey): int
    {
        $lo = 0;
        $hi = \count($entries);

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);

            if (strcmp($entries[$mid]['key'], $partKey) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    /**
     * First entry past the equal-first-part run: keys prefixed by the part
     * key compare as equal, everything after them compares greater.
     *
     * @param array<int,array{key:string,line:int}> $entries
     */
    private static function upperBound(array $entries, string $partKey): int
    {
        $lo = 0;
        $hi = \count($entries);

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $key = $entries[$mid]['key'];
            $cmp = str_starts_with($key, $partKey)
                ? 0
                : strcmp($key, $partKey);

            if ($cmp <= 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }
}
