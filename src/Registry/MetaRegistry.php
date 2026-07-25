<?php

declare(strict_types=1);

namespace AV\JsonProvider\Registry;

use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\JsonProviderTableException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\JsonStorageTxHandle;

/**
 * Per-table metadata registry — operates on meta.json.
 * Format: {"tableName":
 *   {"lastInsertedId": N, "lineCount": M, "byteSize": B}, ...}.
 *
 * lastInsertedId is the most recently allocated auto-increment id for the
 * table.
 * 0 on an empty table (no inserts ever). Not rolled back on delete: ids are
 * never reused, gaps in the sequence are normal (cf. SQL AUTO_INCREMENT). An
 * id allocated by allocateId() but never committed (crash before append)
 * leaves a gap — also normal.
 *
 * byteSize is the data file size after the last successfully committed
 * write. Comparing it against the actual file size is the O(1) consistency
 * gate (ensureTableConsistent): a mismatch means a crashed or foreign write
 * and triggers tail repair plus index rebuild. Entries written before the
 * byteSize field existed read as null, which forces that same re-check.
 *
 * indexFormat is the on-disk index key format for the table's index files:
 * a missing field reads as 1 (legacy encoding), 2 is the current
 * prefix-free typed encoding (IndexKey). Readers treat format < 2 indexes
 * as untrusted (full scan); the first write under the table EX lock
 * rebuilds them and stamps 2. The field is per-table, so mixed databases
 * upgrade lazily table by table.
 *
 * Contract: a meta entry must exist for every registered table. It is created
 * by initTable() (called from JsonDataProvider::createTable). Any operation
 * (allocateId / commit* / get*) for a missing table —
 * MetaEntryMissing.
 *
 * Atomicity is provided by JsonStorage::transaction (sidecar lock).
 * The registry knows the format only; physical I/O is delegated.
 */
final class MetaRegistry
{
    /**
     * Reserved meta.json key holding the renameTable crash-recovery
     * marker ({from, to}); never a table entry. IdentifierRules rejects
     * it as a table name, and getTableNames filters it out.
     */
    public const string PENDING_RENAME_KEY = '_pendingRename';

    private const string META_FILE = 'meta.json';

    public function __construct(
        private readonly JsonStorage $storage,
    ) {
    }

    /**
     * Atomically increments lastInsertedId for the table and returns the new
     * id. Does not touch lineCount/byteSize — those are committed after the
     * physical write via commitAppend()/commitRewrite(). A crash between
     * allocateId and the commit leaves an id gap, which is allowed.
     */
    public function allocateId(string $tableName): int
    {
        return $this->storage->transaction(
            self::META_FILE,
            static function (
                array $data,
                JsonStorageTxHandle $h,
            ) use ($tableName): int {
                /** @var array<string, array{lastInsertedId: int, lineCount: int, byteSize?: int}> $data */
                if (!isset($data[$tableName])) {
                    throw new JsonProviderServiceException(
                        JsonProviderErrorEn::MetaEntryMissing,
                        $tableName,
                    );
                }

                $id = $data[$tableName]['lastInsertedId'] + 1;
                $data[$tableName]['lastInsertedId'] = $id;

                $h->save($data);

                return $id;
            },
        );
    }

    /**
     * Commits the line count and byte size after a successful append to the
     * data file. Call only after the appended bytes are on disk.
     */
    public function commitAppend(
        string $tableName,
        int $lineCount,
        int $byteSize,
    ): void {
        $this->commit($tableName, $lineCount, $byteSize);
    }

    /**
     * Commits the line count and byte size after a successful full rewrite
     * of the data file. Call only after the rename made the new file
     * visible.
     */
    public function commitRewrite(
        string $tableName,
        int $lineCount,
        int $byteSize,
    ): void {
        $this->commit($tableName, $lineCount, $byteSize);
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
                /** @var array<string, array{lastInsertedId: int, lineCount: int, byteSize?: int, indexFormat?: int}> $data */
                if (isset($data[$tableName])) {
                    throw new JsonProviderTableException(
                        JsonProviderErrorEn::TableAlreadyExists,
                        $tableName,
                    );
                }

                $data[$tableName] = [
                    'lastInsertedId' => 0,
                    'lineCount'      => 0,
                    'byteSize'       => 0,
                    'indexFormat'    => 2,
                ];
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
     * Returns the committed data file size in bytes, or null when the entry
     * predates the byteSize field (pre-v2 meta) — callers must treat null as
     * "unknown, verify against the actual file".
     */
    public function getByteSize(string $tableName): int | null
    {
        return $this->getEntry($tableName)['byteSize'];
    }

    /**
     * Returns the on-disk index key format for the table. A missing field
     * (pre-v2 meta) reads as 1: the legacy encoding, untrusted by readers.
     * Throws if no meta entry exists for the table.
     */
    public function getIndexFormat(string $tableName): int
    {
        return $this->getEntry($tableName)['indexFormat'];
    }

    /**
     * Stamps the index format after the table's index files were rebuilt
     * with the corresponding encoder. Call only after the rebuilt files
     * are on disk. Throws if no meta entry exists for the table.
     */
    public function stampIndexFormat(string $tableName, int $format): void
    {
        $this->storage->transaction(
            self::META_FILE,
            static function (
                array $data,
                JsonStorageTxHandle $h,
            ) use (
                $tableName,
                $format,
            ): void {
                /** @var array<string, array{lastInsertedId: int, lineCount: int, byteSize?: int, indexFormat?: int}> $data */
                if (!isset($data[$tableName])) {
                    throw new JsonProviderServiceException(
                        JsonProviderErrorEn::MetaEntryMissing,
                        $tableName,
                    );
                }

                $data[$tableName]['indexFormat'] = $format;

                $h->save($data);
            },
        );
    }

    /**
     * Returns whether a meta entry exists for the table.
     */
    public function hasEntry(string $tableName): bool
    {
        /** @var array<string, array{lastInsertedId: int, lineCount: int, byteSize?: int}> $data */
        $data = $this->storage->read(self::META_FILE);

        return isset($data[$tableName]);
    }

    /**
     * Returns the list of all table names that have a meta entry. The
     * reserved _pendingRename marker key is not a table and is filtered
     * out.
     *
     * @return array<int,string>
     */
    public function getTableNames(): array
    {
        /** @var array<string, array{lastInsertedId: int, lineCount: int, byteSize?: int}> $data */
        $data = $this->storage->read(self::META_FILE);

        return array_values(array_filter(
            array_keys($data),
            static fn (string $name): bool => $name
                !== self::PENDING_RENAME_KEY,
        ));
    }

    /**
     * Writes the renameTable crash-recovery marker. Written FIRST in the
     * rename sequence, so any later crash leaves a trigger for repair to
     * re-verify the schema/meta/filesystem agreement.
     */
    public function setPendingRename(string $from, string $to): void
    {
        $this->storage->transaction(
            self::META_FILE,
            static function (
                array $data,
                JsonStorageTxHandle $h,
            ) use (
                $from,
                $to
            ): void {
                $data[self::PENDING_RENAME_KEY] = [
                    'from' => $from,
                    'to'   => $to,
                ];
                $h->save($data);
            },
        );
    }

    /**
     * Returns the pending rename marker, or null when absent or malformed
     * (a malformed marker reads as absent — reconciliation is driven by
     * the actual schema state, the marker is only the trigger).
     *
     * @return null|array{from: string, to: string}
     */
    public function getPendingRename(): array | null
    {
        $data = $this->storage->read(self::META_FILE);
        $raw = $data[self::PENDING_RENAME_KEY] ?? null;

        if (
            !\is_array($raw)
            || !isset($raw['from'], $raw['to'])
            || !\is_string($raw['from'])
            || !\is_string($raw['to'])
        ) {
            return null;
        }

        return ['from' => $raw['from'], 'to' => $raw['to']];
    }

    /**
     * Removes the pending rename marker. Idempotent.
     */
    public function clearPendingRename(): void
    {
        $this->storage->transaction(
            self::META_FILE,
            static function (array $data, JsonStorageTxHandle $h): void {
                if (!\array_key_exists(self::PENDING_RENAME_KEY, $data)) {
                    return;
                }

                unset($data[self::PENDING_RENAME_KEY]);
                $h->save($data);
            },
        );
    }

    /**
     * Moves a table's meta entry to a new name, preserving
     * lastInsertedId/lineCount/byteSize/indexFormat. Idempotent for the
     * rename crash-recovery: when the target entry already exists, the
     * source (if any) is discarded; when neither exists, it is a no-op —
     * the missing entry surfaces through the regular meta checks.
     */
    public function moveEntry(string $from, string $to): void
    {
        $this->storage->transaction(
            self::META_FILE,
            static function (
                array $data,
                JsonStorageTxHandle $h,
            ) use (
                $from,
                $to
            ): void {
                if (isset($data[$to])) {
                    if (isset($data[$from])) {
                        unset($data[$from]);
                        $h->save($data);
                    }

                    return;
                }

                if (!isset($data[$from])) {
                    return;
                }

                $data[$to] = $data[$from];
                unset($data[$from]);
                $h->save($data);
            },
        );
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
                /** @var array<string, array{lastInsertedId: int, lineCount: int, byteSize?: int}> $data */
                if (!isset($data[$tableName])) {
                    throw new JsonProviderServiceException(
                        JsonProviderErrorEn::MetaEntryMissing,
                        $tableName,
                    );
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
                /** @var array<string, array{lastInsertedId: int, lineCount: int, byteSize?: int}> $data */
                if (!isset($data[$tableName])) {
                    return;
                }

                unset($data[$tableName]);
                $h->save($data);
            },
        );
    }

    /**
     * Shared body of commitAppend/commitRewrite: stores the post-write line
     * count and byte size, lazily upgrading a pre-byteSize entry to v2.
     */
    private function commit(
        string $tableName,
        int $lineCount,
        int $byteSize,
    ): void {
        $this->storage->transaction(
            self::META_FILE,
            static function (
                array $data,
                JsonStorageTxHandle $h,
            ) use (
                $tableName,
                $lineCount,
                $byteSize,
            ): void {
                /** @var array<string, array{lastInsertedId: int, lineCount: int, byteSize?: int}> $data */
                if (!isset($data[$tableName])) {
                    throw new JsonProviderServiceException(
                        JsonProviderErrorEn::MetaEntryMissing,
                        $tableName,
                    );
                }

                $data[$tableName]['lineCount'] = $lineCount;
                $data[$tableName]['byteSize'] = $byteSize;

                $h->save($data);
            },
        );
    }

    /**
     * The field types are validated on every read: a hand-edited or
     * foreign-serialized meta.json (a numeric string in lineCount, a
     * missing counter) must surface as a catchable, repairable
     * META_ENTRY_CORRUPT — not as a bare TypeError that takes down every
     * read path including repair itself.
     *
     * @return array{
     *     lastInsertedId: int,
     *     lineCount: int,
     *     byteSize: null|int,
     *     indexFormat: int,
     * }
     */
    private function getEntry(string $tableName): array
    {
        $data = $this->storage->read(self::META_FILE);

        if (!isset($data[$tableName])) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::MetaEntryMissing,
                $tableName,
            );
        }

        $entry = $data[$tableName];

        if (!\is_array($entry)) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::MetaEntryNotObject,
                $tableName,
            );
        }

        $lastInsertedId = $entry['lastInsertedId'] ?? null;
        $lineCount = $entry['lineCount'] ?? null;
        $byteSize = $entry['byteSize'] ?? null;
        $indexFormat = $entry['indexFormat'] ?? 1;

        if (
            !\is_int($lastInsertedId)
            || !\is_int($lineCount)
            || ($byteSize !== null && !\is_int($byteSize))
            || !\is_int($indexFormat)
        ) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::MetaCounterNotInt,
                $tableName,
            );
        }

        return [
            'lastInsertedId' => $lastInsertedId,
            'lineCount'      => $lineCount,
            'byteSize'       => $byteSize,
            'indexFormat'    => $indexFormat,
        ];
    }
}
