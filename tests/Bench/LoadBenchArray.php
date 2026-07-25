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
 *   1. seedDatabase  — lifts the time limit and bulk-seeds the DB; its wall
 *      time is a read on the host's disk write throughput.
 *   2. read/query benchmarks — each pits a provider query path (the marked
 *      "current" method) against a hand-rolled naive readAll implementation,
 *      to show the provider's overhead at scale. The provider materializes
 *      whole tables per query, so it is not expected to win every op; the
 *      goal is to measure the envelope, not a micro-benchmark.
 *   3. dropDatabase  — tears the DB down and restores the time limit.
 *
 * The seed/drop steps must run exactly once, so they use warmup/calls/
 * iterations of 0/1/1 and a trivial `reference` callable (testo requires at
 * least one comparison target; here only the "current" absolute time
 * matters). Heavy I/O per call keeps the query benchmarks at low counts.
 */
final class LoadBenchArray
{
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
        callables: ['fullScan' => [self::class, 'whereEqFullScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function whereEqIndexed(): int
    {
        return \count(
            LoadFixture::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', '=', 500)
                ->selectAllByArray(),
        );
    }

    public static function whereEqFullScan(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(array_filter(
            $rows,
            static fn (array $r): bool => $r['val'] === 500,
        ));
    }

    #[Bench(
        callables: ['fullScan' => [self::class, 'pkLookupFullScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function pkLookupIndexed(): int
    {
        $row = LoadFixture::db()
            ->table(LoadFixture::tableName(0))
            ->where('id', '=', LoadFixture::ROWS)
            ->selectOneByArray();

        return $row === null ? 0 : 1;
    }

    public static function pkLookupFullScan(): int
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
        callables: ['phpSort' => [self::class, 'orderByPhpSort']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function orderByIndexed(): int
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
        callables: ['sliceReadAll' => [self::class, 'deepPageSlice']],
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

    public static function deepPageSlice(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(\array_slice($rows, intdiv(LoadFixture::ROWS, 2), 10));
    }

    #[Bench(
        callables: ['fullScan' => [self::class, 'rangeBetweenFullScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function rangeBetweenIndexed(): int
    {
        return \count(
            LoadFixture::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', 'BETWEEN', [100, 200])
                ->selectAllByArray(),
        );
    }

    public static function rangeBetweenFullScan(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(array_filter(
            $rows,
            static fn (array $r): bool => \is_int($r['val'])
                && $r['val'] >= 100 && $r['val'] <= 200,
        ));
    }

    #[Bench(
        callables: ['fullScan' => [self::class, 'inListFullScan']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function inListIndexed(): int
    {
        return \count(
            LoadFixture::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', 'IN', [1, 50, 100, 500, 999])
                ->selectAllByArray(),
        );
    }

    public static function inListFullScan(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));
        $wanted = [1, 50, 100, 500, 999];

        return \count(array_filter(
            $rows,
            static fn (array $r): bool => \in_array($r['val'], $wanted, true),
        ));
    }

    #[Bench(
        callables: ['naive' => [self::class, 'likeNaive']],
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

    public static function likeNaive(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(array_filter(
            $rows,
            static fn (array $r): bool => \is_string($r['name'])
                && str_starts_with($r['name'], 'row1'),
        ));
    }

    #[Bench(
        callables: ['naive' => [self::class, 'distinctNaive']],
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

    public static function distinctNaive(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));
        $seen = [];

        foreach ($rows as $r) {
            $seen[(string)$r['val']] = true;
        }

        return \count($seen);
    }

    #[Bench(
        callables: ['naive' => [self::class, 'countWhereNaive']],
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

    public static function countWhereNaive(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(array_filter(
            $rows,
            static fn (array $r): bool => \is_int($r['val']) && $r['val'] < 500,
        ));
    }

    #[Bench(
        callables: ['naive' => [self::class, 'projectColumnNaive']],
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

    public static function projectColumnNaive(): int
    {
        $rows = LoadFixture::db()->readAll(LoadFixture::tableName(0));

        return \count(array_column($rows, 'val'));
    }

    #[Bench(
        callables: ['naive' => [self::class, 'crossTableCountNaive']],
        warmup: 0,
        calls: 1,
        iterations: 2,
    )]
    #[ExpectNoAssertions]
    public static function crossTableCount(): int
    {
        $db = LoadFixture::db();
        $total = 0;

        for ($t = 0; $t < LoadFixture::TABLES; $t++) {
            $total += $db->table(LoadFixture::tableName($t))->count();
        }

        return $total;
    }

    public static function crossTableCountNaive(): int
    {
        $db = LoadFixture::db();
        $total = 0;

        for ($t = 0; $t < LoadFixture::TABLES; $t++) {
            $total += \count($db->readAll(LoadFixture::tableName($t)));
        }

        return $total;
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
        LoadFixture::dropAndRestore();

        return 0;
    }

    /**
     * Trivial comparison target for the one-shot seed/drop benchmarks.
     */
    public static function reference(): int
    {
        return 0;
    }
}
