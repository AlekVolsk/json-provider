<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Format;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Validation\ValueValidator;

/**
 * The one gate deciding whether a table's indexes may be trusted, shared
 * by queries, FK probes, the write-path consistency check, count() and the
 * integrity validator — and the one procedure that makes a table fresh.
 *
 * In a generation-1 database the gate compares the data file size with
 * the committed byteSize, exactly as the 1.0 engines do. In generation 2
 * it also compares the file's inode with the stamp the last full rewrite
 * recorded: a rewrite replaces the file through a rename and so always
 * changes the inode, even when the new file has the same size. That
 * closes the window a size-only check leaves open — a rewrite interrupted
 * between the rename and the index rebuild, with a same-length change.
 */
final class TableFreshness
{
    public function __construct(
        private readonly MetaRegistry $meta,
        private readonly NdjsonStorage $ndjson,
        private readonly IndexManager $indexManager,
        private readonly ValueValidator $values,
    ) {
    }

    /**
     * Turns on generation-2 stamps for this database.
     */
    public function enableStamps(): void
    {
        $this->meta->enableDataStamps(
            fn (string $table): int => $this->ndjson->fileStat(
                $table,
                TableSchema::dataFileName($table),
            )['ino'],
        );
    }

    public function stampsEnabled(): bool
    {
        return $this->meta->stampsEnabled();
    }

    public function check(TableSchema $tableSchema): FreshnessEnum
    {
        return $this->verdict($tableSchema, $this->stampsEnabled());
    }

    /**
     * The verdict against a committed snapshot the caller has already
     * read with MetaRegistry::getCommittedFile() — a gate that also needs
     * the index format or the line count reads meta.json once.
     *
     * @param array{
     *     lineCount: int,
     *     byteSize: null|int,
     *     dataIno: null|int,
     *     indexFormat: int,
     * } $committed
     */
    public function checkCommitted(
        TableSchema $tableSchema,
        array $committed,
    ): FreshnessEnum {
        return $this->judgeCommitted(
            $tableSchema,
            $this->stampsEnabled(),
            $committed,
        )[0];
    }

    /**
     * The verdict with the stamp comparison forced on or off — for a
     * status report that must see stamps even when this instance opened
     * the database before it was migrated.
     */
    public function verdict(
        TableSchema $tableSchema,
        bool $stamped,
    ): FreshnessEnum {
        return $this->judge($tableSchema, $stamped)[0];
    }

    /**
     * The committed line count when the table is trusted, null otherwise
     * — count() without conditions answers from it with one read of
     * meta.json and one stat.
     */
    public function trustedLineCount(TableSchema $tableSchema): int | null
    {
        [$freshness, $lineCount] = $this->judge(
            $tableSchema,
            $this->stampsEnabled(),
        );

        return $freshness->trusted() ? $lineCount : null;
    }

    /**
     * Whether the data file holds a line that is not a record, other than
     * a torn unterminated tail — the one such line a crashed append leaves
     * behind, never acknowledged and legitimately dropped by the write-path
     * heal. Any other such line would be lost by a full rewrite.
     */
    public function holdsBrokenRecords(TableSchema $tableSchema): bool
    {
        $table = $tableSchema->name;
        $file = $tableSchema->getFileName();
        $broken = $this->ndjson->readRawLines($table, $file)['broken'];

        return $this->ndjson->brokenBeyondTornTail($table, $file, $broken)
            !== [];
    }

    /**
     * Rebuilds every index of the table from its data file and commits
     * the counters with a fresh stamp, leaving the data file as it is.
     * Refuses (returns false) when the file holds a line that is not a
     * record: the indexes would silently skip it. Requires the table EX
     * lock and a data file whose committed byteSize matches (FRESH,
     * UNSTAMPED or STALE); drift is healed by a full rewrite instead.
     */
    public function refresh(TableSchema $tableSchema): bool
    {
        $table = $tableSchema->name;
        $raw = $this->ndjson->readRawLines($table, $tableSchema->getFileName());

        if ($raw['broken'] !== []) {
            return false;
        }

        $records = $this->values->widenFloats($tableSchema, $raw['records']);
        $this->indexManager->rebuild($tableSchema, $records);
        $this->meta->commitRewrite(
            $table,
            \count($records),
            $this->ndjson->fileSizeBytes($table, $tableSchema->getFileName()),
        );

        if ($this->meta->getIndexFormat($table) < 2) {
            $this->meta->stampIndexFormat($table, 2);
        }

        return true;
    }

    /**
     * @return array{FreshnessEnum, int} the verdict and the committed
     *                                   line count
     */
    private function judge(TableSchema $tableSchema, bool $stamped): array
    {
        try {
            $committed = $this->meta->getCommittedFile($tableSchema->name);
        } catch (JsonProviderException) {
            return [FreshnessEnum::DRIFT, 0];
        }

        return $this->judgeCommitted($tableSchema, $stamped, $committed);
    }

    /**
     * @param array{
     *     lineCount: int,
     *     byteSize: null|int,
     *     dataIno: null|int,
     *     indexFormat: int,
     * } $committed
     *
     * @return array{FreshnessEnum, int} the verdict and the committed
     *                                   line count
     */
    private function judgeCommitted(
        TableSchema $tableSchema,
        bool $stamped,
        array $committed,
    ): array {
        $lineCount = $committed['lineCount'];

        try {
            $stat = $this->ndjson->fileStat(
                $tableSchema->name,
                $tableSchema->getFileName(),
            );
        } catch (JsonProviderException) {
            return [FreshnessEnum::DRIFT, $lineCount];
        }

        if (
            $committed['byteSize'] === null
            || $stat['size'] !== $committed['byteSize']
        ) {
            return [FreshnessEnum::DRIFT, $lineCount];
        }

        if (!$stamped) {
            return [FreshnessEnum::FRESH, $lineCount];
        }

        if ($committed['dataIno'] === null) {
            return [FreshnessEnum::UNSTAMPED, $lineCount];
        }

        return [
            $committed['dataIno'] === $stat['ino']
                ? FreshnessEnum::FRESH
                : FreshnessEnum::STALE,
            $lineCount,
        ];
    }
}
