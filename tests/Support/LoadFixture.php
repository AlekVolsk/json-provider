<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\NdjsonStorage;

/**
 * Bulk load fixture for the benchmark suite.
 *
 * Seeds TABLES tables of ROWS rows each (the stress ceiling: beyond this a
 * real RDBMS is the better tool). Seeding goes straight through
 * NdjsonStorage::write plus a single index rebuild per table — orders of
 * magnitude faster than ROWS individual insert() calls — and runs once per
 * process (guarded by static state).
 *
 * Each table `load_<n>` has columns id/name/val/price/flag and one lookup
 * index on `val`.
 */
final class LoadFixture
{
    public const string DB_PATH = '/tmp/test_json_db_load';
    public const int TABLES = 50;
    public const int ROWS = 100000;

    private static bool $seeded = false;

    private static float $seedSeconds = 0.0;

    private static int $prevTimeLimit = 0;

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
        if (JsonDataProvider::exists(self::DB_PATH)) {
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

        return JsonDataProvider::getInstance(self::DB_PATH);
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
        if (!JsonDataProvider::exists(self::DB_PATH)) {
            return false;
        }

        try {
            $last = self::tableName(self::TABLES - 1);

            return JsonDataProvider::getInstance(self::DB_PATH)
                ->table($last)
                ->count() === self::ROWS;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Removes the load DB directory recursively.
     */
    public static function drop(): void
    {
        if (!is_dir(self::DB_PATH)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                self::DB_PATH,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);

            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir(self::DB_PATH);
    }

    private static function seedAll(): void
    {
        $db = JsonDataProvider::createDatabase(self::DB_PATH);
        $storage = new NdjsonStorage(self::DB_PATH);

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

            $storage->write(
                self::tableName($t),
                $schema->getFileName(),
                $rows,
            );
            $db->invalidateCache(self::tableName($t));
            $db->table(self::tableName($t))->rebuildAllIndexes();
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
