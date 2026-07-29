<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Bench;

use AV\JsonProvider\JsonTable;
use AV\JsonProvider\Tests\Support\Dto\MapRowFullDto;
use AV\JsonProvider\Tests\Support\MappingFixture;
use Testo\Assert\ExpectNoAssertions;
use Testo\Bench;

/**
 * The object tax across filter and ordering modes.
 *
 * Same contract as MappingBenchHydration — "current" is the DTO path,
 * "api:array" is the identical query through the array surface — but here the
 * query itself varies while the DTO stays MapRowFullDto. Two questions are
 * answered at once: how much the objects cost in each mode (the pair inside
 * one benchmark), and how much the modes cost relative to each other (the
 * absolute times across benchmarks).
 *
 * Filters, all on the same 100k-row table:
 *   - filterIndexedEq / filterUnindexedEq return the SAME 100 rows, one
 *     through the indexed `bucket` column, the other through the unindexed
 *     `note`. Their difference is what the index is actually worth here.
 *   - filterIn / filterBetween ask for 500 rows through range operators the
 *     index can serve; filterLike asks for 100 through an operator it cannot.
 *   - filterCompound applies two predicates and returns a third of the table,
 *     so hydration finally dominates the read.
 *
 * Ordering lives in MappingBenchSort, which compares the provider's ORDER BY
 * against sorting in PHP as well as against the object path.
 */
final class MappingBenchQuery
{
    #[Bench(
        callables: ['api:array' => [self::class, 'indexedEqArray']],
        calls: 3,
        iterations: 10,
    )]
    #[ExpectNoAssertions]
    public static function filterIndexedEq(): int
    {
        return self::countDto(
            self::rows()
                ->where('bucket', '=', MappingFixture::narrowBucket()),
        );
    }

    public static function indexedEqArray(): int
    {
        return \count(
            self::rowsArray()
                ->where('bucket', '=', MappingFixture::narrowBucket())
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'unindexedEqArray']],
        calls: 3,
        iterations: 10,
    )]
    #[ExpectNoAssertions]
    public static function filterUnindexedEq(): int
    {
        return self::countDto(
            self::rows()
                ->where('note', '=', MappingFixture::narrowNote()),
        );
    }

    public static function unindexedEqArray(): int
    {
        return \count(
            self::rowsArray()
                ->where('note', '=', MappingFixture::narrowNote())
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'inListArray']],
        calls: 3,
        iterations: 10,
    )]
    #[ExpectNoAssertions]
    public static function filterIn(): int
    {
        return self::countDto(
            self::rows()->where('bucket', 'IN', [1, 2, 3, 4, 5]),
        );
    }

    public static function inListArray(): int
    {
        return \count(
            self::rowsArray()
                ->where('bucket', 'IN', [1, 2, 3, 4, 5])
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'betweenArray']],
        calls: 3,
        iterations: 10,
    )]
    #[ExpectNoAssertions]
    public static function filterBetween(): int
    {
        return self::countDto(
            self::rows()->where('bucket', 'BETWEEN', [100, 104]),
        );
    }

    public static function betweenArray(): int
    {
        return \count(
            self::rowsArray()
                ->where('bucket', 'BETWEEN', [100, 104])
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'likeArray']],
        calls: 3,
        iterations: 10,
    )]
    #[ExpectNoAssertions]
    public static function filterLike(): int
    {
        return self::countDto(
            self::rows()->where('sku', 'LIKE', 'SKU-0001%'),
        );
    }

    public static function likeArray(): int
    {
        return \count(
            self::rowsArray()
                ->where('sku', 'LIKE', 'SKU-0001%')
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'compoundArray']],
        calls: 2,
        iterations: 8,
    )]
    #[ExpectNoAssertions]
    public static function filterCompound(): int
    {
        return self::countDto(
            self::rows()
                ->where('status', '=', 'active')
                ->where('decile', '=', 3),
        );
    }

    public static function compoundArray(): int
    {
        return \count(
            self::rowsArray()
                ->where('status', '=', 'active')
                ->where('decile', '=', 3)
                ->selectAllByArray(),
        );
    }

    /**
     * The rows table with MapRowFullDto bound. The binding is process state,
     * so it settles during warmup and costs one comparison per call after.
     */
    private static function rows(): JsonTable
    {
        return MappingFixture::bind(MapRowFullDto::class)
            ->table(MappingFixture::ROWS_TABLE);
    }

    /**
     * The same table for the array side; no binding is needed there.
     */
    private static function rowsArray(): JsonTable
    {
        return MappingFixture::db()->table(MappingFixture::ROWS_TABLE);
    }

    private static function countDto(JsonTable $table): int
    {
        return \count(iterator_to_array($table->selectAll()));
    }
}
