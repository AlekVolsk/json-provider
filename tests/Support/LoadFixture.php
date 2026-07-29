<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;

/**
 * Bulk load fixture for the benchmark suite.
 *
 * Seeds TABLES tables of ROWS rows each (the stress ceiling: beyond this a
 * real RDBMS is the better tool) through JsonDataProvider::importRecords(),
 * one bulk load per table — orders of magnitude faster than ROWS individual
 * insert() calls, and identical in outcome: records are validated, indexes
 * rebuilt, meta counters committed. Runs once per process (guarded by static
 * state).
 *
 * Each table `load_<n>` has columns id/name/val/price/flag and one lookup
 * index on `val`.
 *
 * The DB lives under a run-unique directory (see TempDir), so two overlapping
 * runs never share — or drop — each other's data, and the tree is removed at
 * process shutdown even if a benchmark aborts before dropDatabase().
 */
final class LoadFixture
{
    public const string DB_PREFIX = 'jp-bench-load';
    public const int TABLES = 50;
    public const int ROWS = 100000;

    private static bool $seeded = false;

    private static float $seedSeconds = 0.0;

    private static int $prevTimeLimit = 0;

    /**
     * The run-unique database directory: "<temp>/jp-bench-load-<token>". Every
     * caller resolves the load DB through this, so a concurrent run works on
     * its own tree.
     */
    public static function dbPath(): string
    {
        return TempDir::root(self::DB_PREFIX);
    }

    /**
     * Ensures the load DB is present (idempotent, reuses an on-disk seed).
     * Used by the read benchmarks; seeding itself is benchmarked via
     * seedFresh().
     */
    public static function boot(): void
    {
        if (self::$seeded) {
            return;
        }

        if (self::alreadySeeded()) {
            self::$seeded = true;

            return;
        }

        self::seedFresh();
    }

    /**
     * Drops any existing DB and bulk-seeds it from scratch, timing the run.
     *
     * The execution-time limit is lifted here (millions of rows can exceed
     * max_execution_time) and restored later by dropAndRestore(). This is
     * the method the seed benchmark measures — its wall time is a signal of
     * the host's disk write throughput.
     */
    public static function seedFresh(): void
    {
        if (JsonDataProvider::exists(self::dbPath())) {
            self::drop();
        }

        self::$prevTimeLimit = (int)\ini_get('max_execution_time');
        set_time_limit(0);

        $start = microtime(true);
        self::seedAll();
        self::$seedSeconds = microtime(true) - $start;
        self::$seeded = true;
    }

    /**
     * Drops the load DB and restores the execution-time limit that
     * seedFresh() lifted. This is the method the teardown benchmark
     * measures.
     */
    public static function dropAndRestore(): void
    {
        self::drop();
        self::$seeded = false;
        set_time_limit(self::$prevTimeLimit);
    }

    /**
     * Wall time of the last seedFresh() run, in seconds.
     */
    public static function seedSeconds(): float
    {
        return self::$seedSeconds;
    }

    /**
     * Returns the provider singleton (seeding the load DB if needed).
     */
    public static function db(): JsonDataProvider
    {
        self::boot();

        return JsonDataProvider::getInstance(self::dbPath());
    }

    public static function tableName(int $index): string
    {
        return 'load_' . $index;
    }

    /**
     * Returns whether the DB is already fully seeded on disk (so a fresh
     * process can reuse it instead of re-seeding). Checks the last table's
     * row count as the completeness marker.
     */
    public static function alreadySeeded(): bool
    {
        if (!JsonDataProvider::exists(self::dbPath())) {
            return false;
        }

        try {
            $last = self::tableName(self::TABLES - 1);

            return JsonDataProvider::getInstance(self::dbPath())
                ->table($last)
                ->count() === self::ROWS;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Removes the load DB directory recursively. Safe to call when the tree is
     * already gone.
     */
    public static function drop(): void
    {
        TempDir::remove(self::dbPath());
    }

    private static function seedAll(): void
    {
        $db = JsonDataProvider::createDatabase(self::dbPath());

        for ($t = 0; $t < self::TABLES; $t++) {
            $schema = self::tableSchema(self::tableName($t));
            $db->createTable($schema);

            $rows = [];

            for ($i = 1; $i <= self::ROWS; $i++) {
                $rows[] = [
                    'id'    => $i,
                    'name'  => 'row' . $i,
                    'val'   => $i % 1000,
                    'price' => round($i * 1.5, 2),
                    'flag'  => $i % 2 === 0,
                ];
            }

            $db->importRecords(self::tableName($t), $rows);
        }
    }

    private static function tableSchema(string $name): TableSchema
    {
        return TableSchema::create(
            name: $name,
            columns: [
                'id'    => 'int',
                'name'  => 'string',
                'val'   => 'int',
                'price' => 'float',
                'flag'  => 'bool',
            ],
            indexes: [
                new IndexSchema(
                    name: 'idx_val',
                    fields: [new IndexFieldSchema(
                        'val',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        );
    }
}
