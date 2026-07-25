<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Bench;

use AV\JsonProvider\Tests\Support\LoadFixture;
use Testo\Assert\ExpectNoAssertions;
use Testo\Bench;

/**
 * Mutation and maintenance benchmarks over LoadFixture (TABLES tables of ROWS
 * rows — the stress ceiling beyond which a real RDBMS is the better tool).
 *
 * LoadBenchArray/Dto cover the read side, each pitting a query path against a
 * hand-rolled baseline. The write side has no meaningful naive counterpart (a
 * hand-rolled rewrite would just reimplement the provider), so these measure
 * ABSOLUTE wall time against a trivial reference — exactly like the seed/drop
 * one-shots. The signal is each operation's cost at scale, not a ratio.
 *
 * Every operation here is O(rows): a single insert appends and updates the
 * index; update/delete/optimize/rebuild rewrite a whole table; validate,
 * backup and restore span the whole DB (TABLES * ROWS). They run as an ordered
 * flow — seed → mutate load_0 → whole-DB maintenance → restore → drop — with
 * the execution-time limit lifted by seedFresh(). State evolves across steps by
 * design; nothing is asserted (#[ExpectNoAssertions]).
 */
final class LoadBenchWrite
{
    private static string | null $archive = null;

    #[Bench(
        callables: ['reference' => [self::class, 'reference']],
        warmup: 0,
        calls: 1,
        iterations: 1,
    )]
    #[ExpectNoAssertions]
    public static function seedDatabase(): int
    {
        LoadFixture::seedFresh();

        return LoadFixture::TABLES * LoadFixture::ROWS;
    }

    #[Bench(
        callables: ['reference' => [self::class, 'reference']],
        warmup: 0,
        calls: 2,
        iterations: 6,
    )]
    #[ExpectNoAssertions]
    public static function insertRow(): int
    {
        return LoadFixture::db()
            ->table(LoadFixture::tableName(0))
            ->insertByArray([
                'name'  => 'appended',
                'val'   => 500,
                'price' => 9.99,
                'flag'  => true,
            ]);
    }

    #[Bench(
        callables: ['reference' => [self::class, 'reference']],
        warmup: 0,
        calls: 1,
        iterations: 2,
    )]
    #[ExpectNoAssertions]
    public static function updateMatching(): int
    {
        LoadFixture::db()
            ->table(LoadFixture::tableName(0))
            ->where('val', '=', 500)
            ->updateByArray(['flag' => false]);

        return 0;
    }

    #[Bench(
        callables: ['reference' => [self::class, 'reference']],
        warmup: 0,
        calls: 1,
        iterations: 2,
    )]
    #[ExpectNoAssertions]
    public static function deleteMatching(): int
    {
        LoadFixture::db()
            ->table(LoadFixture::tableName(0))
            ->where('val', '=', 999)
            ->delete();

        return 0;
    }

    #[Bench(
        callables: ['reference' => [self::class, 'reference']],
        warmup: 0,
        calls: 1,
        iterations: 2,
    )]
    #[ExpectNoAssertions]
    public static function optimizeTable(): int
    {
        LoadFixture::db()->optimizeTable(LoadFixture::tableName(0));

        return 0;
    }

    #[Bench(
        callables: ['reference' => [self::class, 'reference']],
        warmup: 0,
        calls: 1,
        iterations: 2,
    )]
    #[ExpectNoAssertions]
    public static function rebuildIndexes(): int
    {
        LoadFixture::db()
            ->table(LoadFixture::tableName(0))
            ->rebuildAllIndexes();

        return 0;
    }

    #[Bench(
        callables: ['reference' => [self::class, 'reference']],
        warmup: 0,
        calls: 1,
        iterations: 1,
    )]
    #[ExpectNoAssertions]
    public static function validateDatabase(): int
    {
        return LoadFixture::db()->validate()->hasErrors() ? 1 : 0;
    }

    #[Bench(
        callables: ['reference' => [self::class, 'reference']],
        warmup: 0,
        calls: 1,
        iterations: 1,
    )]
    #[ExpectNoAssertions]
    public static function backupDatabase(): int
    {
        self::$archive = LoadFixture::db()->backup(
            sys_get_temp_dir() . '/jp-load-backup-' . getmypid() . '.tar.gz',
        );
        $size = filesize(self::$archive);

        return $size === false ? 0 : $size;
    }

    #[Bench(
        callables: ['reference' => [self::class, 'reference']],
        warmup: 0,
        calls: 1,
        iterations: 1,
    )]
    #[ExpectNoAssertions]
    public static function restoreDatabase(): int
    {
        if (self::$archive !== null) {
            LoadFixture::db()->restore(self::$archive);
        }

        return 0;
    }

    #[Bench(
        callables: ['reference' => [self::class, 'reference']],
        warmup: 0,
        calls: 1,
        iterations: 1,
    )]
    #[ExpectNoAssertions]
    public static function dropDatabase(): int
    {
        if (self::$archive !== null && is_file(self::$archive)) {
            unlink(self::$archive);
            self::$archive = null;
        }

        LoadFixture::dropAndRestore();

        return 0;
    }

    /**
     * Trivial comparison target for the one-shot benchmarks.
     */
    public static function reference(): int
    {
        return 0;
    }
}
