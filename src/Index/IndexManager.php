<?php

declare(strict_types=1);

namespace AV\JsonProvider\Index;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
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
 * Index entry format: {"key":"encoded_key","line":N}.
 * Entries are sorted by key (strcmp), enabling range lookups.
 */
final class IndexManager
{
    public function __construct(
        private readonly NdjsonStorage $storage,
    ) {}

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
     * Throws StorageException::indexNotFound if no index matches.
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

        throw StorageException::indexNotFound($tableSchema->name, $indexName);
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
     * Returns main-file line numbers that satisfy the condition via the index.
     * Returns null if the index cannot be applied (LIKE, NOT).
     * Returns an empty array if no rows match.
     * Only conditions on the first index field are supported.
     *
     * @return null|array<int,int>
     */
    public function searchLines(
        string $tableName,
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

        $entries = $this->readIndex($tableName, $index);

        if ($entries === []) {
            return [];
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
     * Rebuilds a single index file from scratch using the given records.
     * Atomic at the file level: NdjsonStorage::write replaces the file under
     * flock.
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
        $lines = [];

        foreach ($entries as $entry) {
            if ($entry['key'] === $target) {
                $lines[] = $entry['line'];
            }
        }

        return $lines;
    }

    /**
     * IN: multiple exact lookups, results merged.
     *
     * @param array<int,array{key:string,line:int}> $entries
     *
     * @return array<int,int>
     */
    private function searchIn(
        array $entries,
        IndexFieldSchema $fieldSchema,
        mixed $values,
    ): array {
        if (!\is_array($values) || $values === []) {
            return [];
        }

        $targets = [];

        foreach ($values as $v) {
            if (\is_scalar($v) || $v === null) {
                $targets[] = IndexKey::buildFromValue($v, $fieldSchema);
            }
        }

        if ($targets === []) {
            return [];
        }

        $targetSet = array_flip($targets);
        $lines = [];

        foreach ($entries as $entry) {
            if (isset($targetSet[$entry['key']])) {
                $lines[] = $entry['line'];
            }
        }

        return $lines;
    }

    /**
     * Range search: from $from to $to (both bounds optional).
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
        $fromKey = $from !== null
            ? IndexKey::buildFromValue($from, $fieldSchema)
            : null;
        $toKey = $to !== null
            ? IndexKey::buildFromValue($to, $fieldSchema)
            : null;
        $lines = [];

        foreach ($entries as $entry) {
            $key = $entry['key'];

            if ($fromKey !== null) {
                $cmp = strcmp($key, $fromKey);

                if ($fromInclusive ? $cmp < 0 : $cmp <= 0) {
                    continue;
                }
            }

            if ($toKey !== null) {
                $cmp = strcmp($key, $toKey);

                if ($toInclusive ? $cmp > 0 : $cmp >= 0) {
                    continue;
                }
            }

            $lines[] = $entry['line'];
        }

        return $lines;
    }

    /**
     * @param array<int,array{key:string,line:int}> $entries
     *
     * @return array<int,int>
     */
    private function searchBetween(
        array $entries,
        IndexFieldSchema $fieldSchema,
        mixed $value,
    ): array {
        if (!\is_array($value) || !isset($value[0], $value[1])) {
            return [];
        }

        $from = \is_scalar($value[0]) ? $value[0] : null;
        $to = \is_scalar($value[1]) ? $value[1] : null;

        return $this->searchRange(
            $entries,
            $fieldSchema,
            $from,
            $to,
            true,
            true,
        );
    }
}
