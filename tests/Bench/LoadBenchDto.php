<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Bench;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Tests\Support\Dto\LoadRowDto;
use AV\JsonProvider\Tests\Support\LoadFixture;
use Testo\Assert\ExpectNoAssertions;
use Testo\Bench;

/**
 * Object-surface counterpart to LoadBenchArray over the same LoadFixture DB
 * (TABLES tables of ROWS rows — the stress ceiling beyond which a real RDBMS
 * is the better tool).
 *
 * Where LoadBenchArray pits the provider's query against a hand-rolled readAll,
 * this suite isolates the **DTO hydration cost**: each benchmark's marked
 * (reported as "current") method runs a query through the typed object surface
 * (selectAll → iterable<LoadRowDto>, selectOne → LoadRowDto) and its comparison
 * callable runs the very same query through the array surface. The delta is the
 * price of turning rows into objects at scale.
 *
 * The object tax is proportional to the number of rows RETURNED, so the
 * where/pk/order/deepPage benchmarks (bounded by their limit or predicate)
 * only pay a small, fixed amount on top of the file scan. fullScanDto is the
 * unbounded case — it hydrates the whole table (ROWS objects) against the same
 * array read, exposing the per-row hydration cost in its purest form.
 *
 * The seed/drop steps mirror LoadBenchArray so the suite is self-contained
 * (own 0/1/1 one-shot benchmarks). crossTableCount has no object dimension
 * (count() returns an int, not rows) and lives only in the array suite.
 */
final class LoadBenchDto
{
    private static JsonDataProvider | null $registeredOn = null;

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
    )]
    #[ExpectNoAssertions]
    public static function seedForDto(): int
    {
        LoadFixture::seedFresh();

        return LoadFixture::TABLES * LoadFixture::ROWS;
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'whereEqArray']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function whereEqDto(): int
    {
        return \count(iterator_to_array(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', '=', 500)
                ->selectAll(),
        ));
    }

    public static function whereEqArray(): int
    {
        return \count(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', '=', 500)
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'pkLookupArray']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function pkLookupDto(): int
    {
        $dto = self::db()
            ->table(LoadFixture::tableName(0))
            ->where('id', '=', LoadFixture::ROWS)
            ->selectOne();

        return $dto === null ? 0 : 1;
    }

    public static function pkLookupArray(): int
    {
        $row = self::db()
            ->table(LoadFixture::tableName(0))
            ->where('id', '=', LoadFixture::ROWS)
            ->selectOneByArray();

        return $row === null ? 0 : 1;
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'orderByArray']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function orderByDto(): int
    {
        return \count(iterator_to_array(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->orderBy('val', 'asc')
                ->limit(100)
                ->selectAll(),
        ));
    }

    public static function orderByArray(): int
    {
        return \count(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->orderBy('val', 'asc')
                ->limit(100)
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'deepPageArray']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function deepPageDto(): int
    {
        return \count(iterator_to_array(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->offset(intdiv(LoadFixture::ROWS, 2))
                ->limit(10)
                ->selectAll(),
        ));
    }

    public static function deepPageArray(): int
    {
        return \count(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->offset(intdiv(LoadFixture::ROWS, 2))
                ->limit(10)
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'fullScanArray']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function fullScanDto(): int
    {
        return \count(iterator_to_array(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->selectAll(),
        ));
    }

    public static function fullScanArray(): int
    {
        return \count(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'rangeBetweenArray']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function rangeBetweenDto(): int
    {
        return \count(iterator_to_array(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', 'BETWEEN', [100, 200])
                ->selectAll(),
        ));
    }

    public static function rangeBetweenArray(): int
    {
        return \count(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', 'BETWEEN', [100, 200])
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'inListArray']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function inListDto(): int
    {
        return \count(iterator_to_array(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', 'IN', [1, 50, 100, 500, 999])
                ->selectAll(),
        ));
    }

    public static function inListArray(): int
    {
        return \count(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->where('val', 'IN', [1, 50, 100, 500, 999])
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'likeArray']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function likeDto(): int
    {
        return \count(iterator_to_array(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->where('name', 'LIKE', 'row1%')
                ->selectAll(),
        ));
    }

    public static function likeArray(): int
    {
        return \count(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->where('name', 'LIKE', 'row1%')
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'distinctArray']],
        calls: 3,
        iterations: 12,
    )]
    #[ExpectNoAssertions]
    public static function distinctDto(): int
    {
        return \count(iterator_to_array(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->distinct('val')
                ->selectAll(),
        ));
    }

    public static function distinctArray(): int
    {
        return \count(
            self::db()
                ->table(LoadFixture::tableName(0))
                ->distinct('val')
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
    )]
    #[ExpectNoAssertions]
    public static function dropAfterDto(): int
    {
        LoadFixture::dropAndRestore();
        self::$registeredOn = null;

        return 0;
    }

    /**
     * Empty comparison target for the one-shot seed/drop benchmarks: it exists
     * only because a benchmark needs at least one callable, and it always takes
     * first place. Read the absolute time of "current", not the ranking.
     */
    public static function noop(): int
    {
        return 0;
    }

    /**
     * The load provider with LoadRowDto bound. Registration happens once per
     * provider instance (seedFresh() replaces the singleton), off the timed
     * path — the identity check keeps it O(1) inside the benchmark loop.
     */
    private static function db(): JsonDataProvider
    {
        $db = LoadFixture::db();

        if (self::$registeredOn !== $db) {
            $db->registerDto(LoadRowDto::class);
            self::$registeredOn = $db;
        }

        return $db;
    }
}
