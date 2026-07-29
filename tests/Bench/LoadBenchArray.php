<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Bench;

use AV\JsonProvider\Tests\Support\LoadFixture;
use Testo\Assert\ExpectNoAssertions;
use Testo\Bench;

/**
 * Load benchmarks over LoadFixture (TABLES tables of ROWS rows each — the
 * stress ceiling beyond which a real RDBMS is the better tool).
 *
 * The suite runs as an ordered flow (methods execute in definition order):
 *   1. seedForRead — lifts the time limit and bulk-seeds the DB. Its wall time
 *      is dominated by rebuilding one index pair per table, not by the write
 *      itself.
 *   2. read/query benchmarks — each pits a provider query path (the marked
 *      method, reported as "current") against a hand-rolled readAll
 *      implementation (reported as "php:scan" / "php:sort" / "php:slice"), to
 *      show the provider's overhead at scale. The provider materializes whole
 *      tables per query, so it is not expected to win every op; the goal is to
 *      measure the envelope, not a micro-benchmark.
 *   3. dropAfterRead — tears the DB down and restores the time limit.
 *
 * Only the whole-DB steps (seed, drop, countAcrossAllTables) touch all TABLES
 * tables; every query benchmark reads table 0 alone, so its numbers describe
 * ROWS rows, not TABLES * ROWS.
 *
 * The seed/drop steps must run exactly once, so they use warmup/calls/
 * iterations of 0/1/1 and a trivial `noop` callable (testo requires at least
 * one comparison target; here only the "current" absolute time matters). Heavy
 * I/O per call keeps the query benchmarks at low counts.
 */
final class LoadBenchArray
{
    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
    )]
    #[ExpectNoAssertions]
    public static function seedForRead(): int
    {
        LoadFixture::seedFresh();

        return LoadFixture::TABLES * LoadFixture::ROWS;
    }

    #[Bench(
        callables: ['php:scan' => [self::class, 'whereEqPhpScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function whereEqQuery(): int
    {
        return \count(
            LoadFixture::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', '=', 500)
                ->selectAllByArray(),
        );
    }

    public static function whereEqPhpScan(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(array_filter(
            $rows,
            static fn (array $r): bool => $r['val'] === 500,
        ));
    }

    #[Bench(
        callables: ['php:scan' => [self::class, 'pkLookupPhpScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function pkLookupQuery(): int
    {
        $row = LoadFixture::db()
            ->table(LoadFixture::tableName(0))
            ->where('id', '=', LoadFixture::ROWS)
            ->selectOneByArray();

        return $row === null ? 0 : 1;
    }

    public static function pkLookupPhpScan(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        foreach ($rows as $r) {
            if ($r['id'] === LoadFixture::ROWS) {
                return 1;
            }
        }

        return 0;
    }

    #[Bench(
        callables: ['php:sort' => [self::class, 'orderByPhpSort']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function orderByQuery(): int
    {
        return \count(
            LoadFixture::db()
                ->table(LoadFixture::tableName(0))
                ->orderBy('val', 'asc')
                ->limit(100)
                ->selectAllByArray(),
        );
    }

    public static function orderByPhpSort(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));
        usort(
            $rows,
            static fn (array $a, array $b): int => $a['val'] <=> $b['val'],
        );

        return \count(\array_slice($rows, 0, 100));
    }

    #[Bench(
        callables: ['php:slice' => [self::class, 'deepPagePhpSlice']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function deepPageQuery(): int
    {
        return \count(
            LoadFixture::db()
                ->table(LoadFixture::tableName(0))
                ->offset(intdiv(LoadFixture::ROWS, 2))
                ->limit(10)
                ->selectAllByArray(),
        );
    }

    public static function deepPagePhpSlice(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(\array_slice($rows, intdiv(LoadFixture::ROWS, 2), 10));
    }

    #[Bench(
        callables: ['php:scan' => [self::class, 'rangeBetweenPhpScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function rangeBetweenQuery(): int
    {
        return \count(
            LoadFixture::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', 'BETWEEN', [100, 200])
                ->selectAllByArray(),
        );
    }

    public static function rangeBetweenPhpScan(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(array_filter(
            $rows,
            static fn (array $r): bool => \is_int($r['val'])
                && $r['val'] >= 100 && $r['val'] <= 200,
        ));
    }

    #[Bench(
        callables: ['php:scan' => [self::class, 'inListPhpScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function inListQuery(): int
    {
        return \count(
            LoadFixture::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', 'IN', [1, 50, 100, 500, 999])
                ->selectAllByArray(),
        );
    }

    public static function inListPhpScan(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));
        $wanted = [1, 50, 100, 500, 999];

        return \count(array_filter(
            $rows,
            static fn (array $r): bool => \in_array($r['val'], $wanted, true),
        ));
    }

    #[Bench(
        callables: ['php:scan' => [self::class, 'likePhpScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function likeScan(): int
    {
        return \count(
            LoadFixture::db()
                ->table(LoadFixture::tableName(0))
                ->where('name', 'LIKE', 'row1%')
                ->selectAllByArray(),
        );
    }

    public static function likePhpScan(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(array_filter(
            $rows,
            static fn (array $r): bool => \is_string($r['name'])
                && str_starts_with($r['name'], 'row1'),
        ));
    }

    #[Bench(
        callables: ['php:scan' => [self::class, 'distinctPhpScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function distinctValues(): int
    {
        return \count(
            LoadFixture::db()
                ->table(LoadFixture::tableName(0))
                ->isDistinct('val')
                ->selectAllByArray(),
        );
    }

    public static function distinctPhpScan(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));
        $seen = [];

        foreach ($rows as $r) {
            $seen[(string)$r['val']] = true;
        }

        return \count($seen);
    }

    #[Bench(
        callables: ['php:scan' => [self::class, 'countWherePhpScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function countWhere(): int
    {
        return LoadFixture::db()
            ->table(LoadFixture::tableName(0))
            ->where('val', '<', 500)
            ->count();
    }

    public static function countWherePhpScan(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(array_filter(
            $rows,
            static fn (array $r): bool => \is_int($r['val']) && $r['val'] < 500,
        ));
    }

    #[Bench(
        callables: ['php:scan' => [self::class, 'projectColumnPhpScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function projectColumn(): int
    {
        return \count(
            LoadFixture::db()
                ->table(LoadFixture::tableName(0))
                ->selectColumn('val'),
        );
    }

    public static function projectColumnPhpScan(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(array_column($rows, 'val'));
    }

    #[Bench(
        callables: ['php:scan' => [self::class, 'countAcrossAllTablesPhpScan']],
        warmup: 0,
        calls: 1,
        iterations: 2,
    )]
    #[ExpectNoAssertions]
    public static function countAcrossAllTables(): int
    {
        $db = LoadFixture::db();
        $total = 0;

        for ($t = 0; $t < LoadFixture::TABLES; $t++) {
            $total += $db->table(LoadFixture::tableName($t))->count();
        }

        return $total;
    }

    public static function countAcrossAllTablesPhpScan(): int
    {
        $db = LoadFixture::db();
        $total = 0;

        for ($t = 0; $t < LoadFixture::TABLES; $t++) {
            $total += \count($db->readAll(LoadFixture::tableName($t)));
        }

        return $total;
    }

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
    )]
    #[ExpectNoAssertions]
    public static function dropAfterRead(): int
    {
        LoadFixture::dropAndRestore();

        return 0;
    }

    /**
     * Empty comparison target for the one-shot seed/drop benchmarks: it exists
     * only because a benchmark needs at least one callable, and it always
     * takes first place. Read the absolute time of "current", not the ranking.
     */
    public static function noop(): int
    {
        return 0;
    }
}
