<?php

declare(strict_types=1);

namespace AV\JsonProvider\Registry;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\JsonStorageTxHandle;

/**
 * Per-table metadata registry — operates on meta.json.
 * Format: {"tableName": {"lastInsertedId": N, "lineCount": M}, ...}.
 *
 * lastInsertedId is the most recently allocated auto-increment id for the
 * table.
 * 0 on an empty table (no inserts ever). Not rolled back on delete: ids are
 * never reused, gaps in the sequence are normal (cf. SQL AUTO_INCREMENT).
 *
 * Contract: a meta entry must exist for every registered table. It is created
 * by initTable() (called from JsonDataProvider::createTable). Any operation
 * (allocateInsert / setLineCount / get*) for a missing table —
 * StorageException::metaEntryMissing.
 *
 * Atomicity is provided by JsonStorage::transaction (flock).
 * The registry knows the format only; physical I/O is delegated.
 */
final class MetaRegistry
{
    private const string META_FILE = 'meta.json';

    public function __construct(
        private readonly JsonStorage $storage,
    ) {}

    /**
     * Atomically increments lastInsertedId for the table and returns the
     * new id.
     * Also increments lineCount; line is the 0-based row number prior to
     * the increment.
     *
     * @return array{id: int, line: int}
     */
    public function allocateInsert(string $tableName): array
    {
        return $this->storage->transaction(
            self::META_FILE,
            static function (
                array $data,
                JsonStorageTxHandle $h,
            ) use ($tableName): array {
                /** @var array<string, array{lastInsertedId: int, lineCount: int}> $data */
                if (!isset($data[$tableName])) {
                    throw StorageException::metaEntryMissing($tableName);
                }

                $id = $data[$tableName]['lastInsertedId'] + 1;
                $line = $data[$tableName]['lineCount'];

                $data[$tableName]['lastInsertedId'] = $id;
                $data[$tableName]['lineCount'] = $line + 1;

                $h->save($data);

                return ['id' => $id, 'line' => $line];
            },
        );
    }

    /**
     * Sets the exact lineCount value after a full table rewrite.
     */
    public function setLineCount(string $tableName, int $count): void
    {
        $this->storage->transaction(
            self::META_FILE,
            static function (
                array $data,
                JsonStorageTxHandle $h,
            ) use (
                $tableName,
                $count,
            ): void {
                /** @var array<string, array{lastInsertedId: int, lineCount: int}> $data */
                if (!isset($data[$tableName])) {
                    throw StorageException::metaEntryMissing($tableName);
                }

                $data[$tableName]['lineCount'] = $count;

                $h->save($data);
            },
        );
    }

    /**
     * Initializes meta data for a new table.
     * Throws if an entry already exists — protects against double registration.
     * Pairs with createTable.
     */
    public function initTable(string $tableName): void
    {
        $this->storage->transaction(
            self::META_FILE,
            static function (
                array $data,
                JsonStorageTxHandle $h,
            ) use ($tableName): void {
                /** @var array<string, array{lastInsertedId: int, lineCount: int}> $data */
                if (isset($data[$tableName])) {
                    throw StorageException::tableAlreadyExists($tableName);
                }

                $data[$tableName] = ['lastInsertedId' => 0, 'lineCount' => 0];
                $h->save($data);
            },
        );
    }

    /**
     * Returns the most recently allocated id for the table.
     * 0 if no inserts have ever happened.
     * Throws if no meta entry exists for the table.
     */
    public function getLastInsertedId(string $tableName): int
    {
        return $this->getEntry($tableName)['lastInsertedId'];
    }

    /**
     * Returns the id that will be allocated to the next insert
     * (lastInsertedId + 1).
     * Does NOT reserve the id for the caller: a parallel insert may "consume"
     * this value, leaving the current caller with lastInsertedId + 2. Use as
     * a prediction (path names, identifiers for external systems before the
     * actual insert).
     */
    public function getNextId(string $tableName): int
    {
        return $this->getLastInsertedId($tableName) + 1;
    }

    /**
     * Returns the current lineCount for the table.
     * Throws if no meta entry exists for the table.
     */
    public function getLineCount(string $tableName): int
    {
        return $this->getEntry($tableName)['lineCount'];
    }

    /**
     * Returns whether a meta entry exists for the table.
     */
    public function hasEntry(string $tableName): bool
    {
        /** @var array<string, array{lastInsertedId: int, lineCount: int}> $data */
        $data = $this->storage->read(self::META_FILE);

        return isset($data[$tableName]);
    }

    /**
     * Returns the list of all table names that have a meta entry.
     *
     * @return array<int,string>
     */
    public function getTableNames(): array
    {
        /** @var array<string, array{lastInsertedId: int, lineCount: int}> $data */
        $data = $this->storage->read(self::META_FILE);

        return array_keys($data);
    }

    /**
     * Sets the lastInsertedId value (used by IntegrityRepairer to fix drift).
     * Throws if no meta entry exists for the table.
     */
    public function setLastInsertedId(string $tableName, int $value): void
    {
        $this->storage->transaction(
            self::META_FILE,
            static function (
                array $data,
                JsonStorageTxHandle $h,
            ) use (
                $tableName,
                $value,
            ): void {
                /** @var array<string, array{lastInsertedId: int, lineCount: int}> $data */
                if (!isset($data[$tableName])) {
                    throw StorageException::metaEntryMissing($tableName);
                }

                $data[$tableName]['lastInsertedId'] = $value;

                $h->save($data);
            },
        );
    }

    /**
     * Removes a table entry from the meta file. Idempotent — missing
     * entry is a no-op.
     */
    public function dropEntry(string $tableName): void
    {
        $this->storage->transaction(
            self::META_FILE,
            static function (
                array $data,
                JsonStorageTxHandle $h,
            ) use ($tableName): void {
                /** @var array<string, array{lastInsertedId: int, lineCount: int}> $data */
                if (!isset($data[$tableName])) {
                    return;
                }

                unset($data[$tableName]);
                $h->save($data);
            },
        );
    }

    /**
     * @return array{lastInsertedId: int, lineCount: int}
     */
    private function getEntry(string $tableName): array
    {
        /** @var array<string, array{lastInsertedId: int, lineCount: int}> $data */
        $data = $this->storage->read(self::META_FILE);

        if (!isset($data[$tableName])) {
            throw StorageException::metaEntryMissing($tableName);
        }

        return $data[$tableName];
    }
}
