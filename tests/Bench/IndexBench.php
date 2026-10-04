<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Bench;

use AV\JsonProvider\Tests\Support\IndexFixture;
use Testo\Bench;

/**
 * Index paths whose cost is planned to change, measured before the change.
 *
 * Every benchmark compares two tables that hold the same rows and differ in
 * exactly one property (see IndexFixture, which verifies each pair before the
 * first measurement). The marked method ("current") takes the side whose cost
 * a planned change targets; the comparison callable takes the side that
 * already has the cheap path. Today "current" loses in most pairs — the gap
 * IS the measurement, and the change that closes it will show up here.
 *
 *  - uniqueInsert: an insert into a table with a unique constraint checks
 *    uniqueness by reading the whole table; the same insert without the
 *    constraint only appends.
 *  - foreignKeySelect: a select on a foreign-key column whose only index is
 *    the engine's service index, which the query planner does not use, against
 *    the same select on a column with a user index.
 *  - compositeTwoFields: equality on both fields of a composite index against
 *    the same query over a single-field index on the first field. The index
 *    is searched by its first field only, yet every composite key carries the
 *    second field too, so the composite side reads a longer index file for
 *    the same candidates and costs more.
 *  - pkLookupLarge: one primary-key lookup in a ROWS-row table against the
 *    same lookup in a SMALL_ROWS-row table. An index lookup reads the whole
 *    index file, so its cost grows with the table, not with the result.
 *  - countIndexed: count() with an indexable condition reads the whole table;
 *    select() with the same condition goes through the index.
 *  - staleIndexQuery: the same indexed query on a table whose stamp went
 *    stale (an older engine rewrote it) and on a fresh one. A stale table is
 *    served by a full scan until the next write repairs it — the price of a
 *    deploy that runs the 1.0 engine side by side. On the 1.0 engine itself
 *    both tables are fresh.
 *
 * foreignKeySelect, countIndexed and staleIndexQuery each pit a full scan
 * against an index lookup, and each comes twice — over narrow rows (a few
 * integers) and, with the "Wide" suffix, over rows of about 200 bytes. A full
 * scan pays per byte of data, an index lookup today pays for reading and
 * checking the whole index file, so over narrow rows the scan can win: which
 * side wins depends on the row width, and both widths are measured so that
 * neither result is mistaken for the general one.
 *
 * The count*Wide benchmarks below form a matrix of count() variations over
 * the same wide table. count() without a condition is answered from the
 * table's meta in O(1); with any condition it reads the whole table today,
 * whatever indexes there are. Each variation is measured against selecting
 * the same rows and counting them in PHP (filters come from
 * IndexFixture::countQuery(), so both sides ask the same question):
 *
 *  - countInWide, countRangeWide: IN and BETWEEN over an indexed column,
 *    which select() serves from the index;
 *  - countLikeWide: LIKE over an unindexed column — both sides scan;
 *  - countCompoundWide: an indexed equality AND an unindexed LIKE;
 *  - countDistinctWide: count() with distinct() goes through select()
 *    itself; measured against counting the distinct values of readAll();
 *  - countAllStaleWide: count() without a condition on a stale table, whose
 *    meta the engine no longer trusts — a full read — against the same
 *    count() on a fresh one, answered from meta. This is the cost a deploy
 *    running the 1.0 engine side by side puts on the cheapest call there is.
 *
 * seedForIndex and dropAfterIndex are one-shot steps measured against an
 * empty `noop`, like the seed/drop steps of the Load suites: only their
 * absolute time means anything.
 */
final class IndexBench
{
    private static int $inserted = 0;

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function seedForIndex(): int
    {
        IndexFixture::seed();

        return IndexFixture::ROWS;
    }

    #[Bench(
        callables: ['no-unique' => [self::class, 'insertWithoutUnique']],
        calls: 3,
        iterations: 6,
        tolerance: INF,
    )]
    public static function uniqueInsert(): int
    {
        return IndexFixture::db()->table(IndexFixture::UNIQUE)
            ->insertByArray(self::nextRow());
    }

    public static function insertWithoutUnique(): int
    {
        return IndexFixture::db()->table(IndexFixture::PLAIN)
            ->insertByArray(self::nextRow());
    }

    #[Bench(
        callables: ['user-index' => [self::class, 'foreignKeyUserIndex']],
        calls: 3,
        iterations: 6,
        tolerance: INF,
    )]
    public static function foreignKeySelect(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::FK_SERVICE)
                ->where('ownerId', '=', IndexFixture::owner())
                ->selectAllByArray(),
        );
    }

    public static function foreignKeyUserIndex(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::FK_USER)
                ->where('ownerId', '=', IndexFixture::owner())
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['user-index' => [self::class, 'foreignKeyUserIndexWide']],
        calls: 3,
        iterations: 6,
        tolerance: INF,
    )]
    public static function foreignKeySelectWide(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::FK_SERVICE_WIDE)
                ->where('ownerId', '=', IndexFixture::owner())
                ->selectAllByArray(),
        );
    }

    public static function foreignKeyUserIndexWide(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::FK_USER_WIDE)
                ->where('ownerId', '=', IndexFixture::owner())
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['single-index' => [self::class, 'singleFieldIndex']],
        calls: 3,
        iterations: 6,
        tolerance: INF,
    )]
    public static function compositeTwoFields(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::COMPOSITE)
                ->where('a', '=', 7)
                ->where('b', '=', 42)
                ->selectAllByArray(),
        );
    }

    public static function singleFieldIndex(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::SINGLE)
                ->where('a', '=', 7)
                ->where('b', '=', 42)
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['small-table' => [self::class, 'pkLookupSmall']],
        calls: 3,
        iterations: 6,
        tolerance: INF,
    )]
    public static function pkLookupLarge(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::PLAIN)
                ->where('id', '=', 700)
                ->selectAllByArray(),
        );
    }

    public static function pkLookupSmall(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::SMALL)
                ->where('id', '=', 700)
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['select-count' => [self::class, 'countBySelect']],
        calls: 3,
        iterations: 6,
        tolerance: INF,
    )]
    public static function countIndexed(): int
    {
        return IndexFixture::db()->table(IndexFixture::FRESH)
            ->where('val', '=', 7)
            ->count();
    }

    public static function countBySelect(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::FRESH)
                ->where('val', '=', 7)
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['select-count' => [self::class, 'countBySelectWide']],
        calls: 3,
        iterations: 6,
        tolerance: INF,
    )]
    public static function countIndexedWide(): int
    {
        return IndexFixture::db()->table(IndexFixture::FRESH_WIDE)
            ->where('val', '=', 7)
            ->count();
    }

    public static function countBySelectWide(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::FRESH_WIDE)
                ->where('val', '=', 7)
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['select-count' => [self::class, 'countInBySelect']],
        calls: 2,
        iterations: 6,
        tolerance: INF,
    )]
    public static function countInWide(): int
    {
        return IndexFixture::countQuery('in')->count();
    }

    public static function countInBySelect(): int
    {
        return \count(IndexFixture::countQuery('in')->selectAllByArray());
    }

    #[Bench(
        callables: ['select-count' => [self::class, 'countRangeBySelect']],
        calls: 2,
        iterations: 6,
        tolerance: INF,
    )]
    public static function countRangeWide(): int
    {
        return IndexFixture::countQuery('range')->count();
    }

    public static function countRangeBySelect(): int
    {
        return \count(IndexFixture::countQuery('range')->selectAllByArray());
    }

    #[Bench(
        callables: ['select-count' => [self::class, 'countLikeBySelect']],
        calls: 2,
        iterations: 6,
        tolerance: INF,
    )]
    public static function countLikeWide(): int
    {
        return IndexFixture::countQuery('like')->count();
    }

    public static function countLikeBySelect(): int
    {
        return \count(IndexFixture::countQuery('like')->selectAllByArray());
    }

    #[Bench(
        callables: ['select-count' => [self::class, 'countCompoundBySelect']],
        calls: 2,
        iterations: 6,
        tolerance: INF,
    )]
    public static function countCompoundWide(): int
    {
        return IndexFixture::countQuery('compound')->count();
    }

    public static function countCompoundBySelect(): int
    {
        return \count(
            IndexFixture::countQuery('compound')->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['php:unique' => [self::class, 'countDistinctPhp']],
        calls: 2,
        iterations: 6,
        tolerance: INF,
    )]
    public static function countDistinctWide(): int
    {
        return IndexFixture::countQuery('distinct')->count();
    }

    public static function countDistinctPhp(): int
    {
        $rows = IndexFixture::db()->readAll(IndexFixture::FRESH_WIDE);

        return \count(array_unique(array_column($rows, 'val')));
    }

    #[Bench(
        callables: ['fresh' => [self::class, 'countAllFreshWide']],
        calls: 2,
        iterations: 6,
        tolerance: INF,
    )]
    public static function countAllStaleWide(): int
    {
        return IndexFixture::db()->table(IndexFixture::STALE_WIDE)->count();
    }

    public static function countAllFreshWide(): int
    {
        return IndexFixture::db()->table(IndexFixture::FRESH_WIDE)->count();
    }

    #[Bench(
        callables: ['fresh' => [self::class, 'freshIndexQuery']],
        calls: 3,
        iterations: 6,
        tolerance: INF,
    )]
    public static function staleIndexQuery(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::STALE)
                ->where('val', '=', 7)
                ->selectAllByArray(),
        );
    }

    public static function freshIndexQuery(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::FRESH)
                ->where('val', '=', 7)
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['fresh' => [self::class, 'freshIndexQueryWide']],
        calls: 3,
        iterations: 6,
        tolerance: INF,
    )]
    public static function staleIndexQueryWide(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::STALE_WIDE)
                ->where('val', '=', 7)
                ->selectAllByArray(),
        );
    }

    public static function freshIndexQueryWide(): int
    {
        return \count(
            IndexFixture::db()->table(IndexFixture::FRESH_WIDE)
                ->where('val', '=', 7)
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function dropAfterIndex(): int
    {
        IndexFixture::drop();

        return 0;
    }

    /**
     * Empty comparison target for the one-shot steps; read the absolute
     * time of "current", not the ranking.
     */
    public static function noop(): int
    {
        return 0;
    }

    /**
     * A row with an email no earlier insert used, so the unique check
     * passes on every call.
     *
     * @return array{email: string, val: int}
     */
    private static function nextRow(): array
    {
        self::$inserted++;

        return [
            'email' => 'bench' . self::$inserted . '@example.test',
            'val'   => self::$inserted % 1000,
        ];
    }
}
