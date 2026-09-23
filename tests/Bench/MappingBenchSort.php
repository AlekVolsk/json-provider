<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Bench;

use AV\JsonProvider\JsonTable;
use AV\JsonProvider\Query\ComparisonModeEnum;
use AV\JsonProvider\Tests\Support\Dto\MapRowFullDto;
use AV\JsonProvider\Tests\Support\MappingFixture;
use Testo\Assert\ExpectNoAssertions;
use Testo\Bench;

/**
 * Who should do the sorting, measured four ways per benchmark.
 *
 * Every benchmark produces the SAME ordered rows through four routes:
 *
 *   current           ORDER BY inside the query, hydrated into DTOs
 *   api:array         ORDER BY inside the query, raw arrays
 *   php:sort-result   the same query WITHOUT ORDER BY, usort() over the
 *                     returned result
 *   php:scan-sort     readAll() + filter + usort(), the provider's query
 *                     layer bypassed entirely
 *
 * The first two answer "what do objects cost here", the last two answer "who
 * should do the ordering" — and the answer turned out to depend entirely on
 * how many rows have to be MATERIALIZED, not on the comparison itself.
 *
 * The engine sorts with the same usort() (see JsonDataProvider::sortByOrdering)
 * but routes every comparison through ValueComparator::compare, a method call
 * that re-dispatches on null-ness, type and ComparisonModeEnum per pair, where
 * the PHP side inlines one strcmp. That overhead is real and shows up once the
 * result is already narrow: over a 10k-row selection php:sort-result comes out
 * ~12% ahead of the DTO path.
 *
 * It stops mattering as soon as the whole table is in play. ORDER BY + LIMIT
 * lets the engine decode only the rows it returns, while readAll() decodes all
 * ROWS of them before usort() ever runs — so over the full table the provider
 * wins by ~73% and uses roughly half the memory (~139 MB against ~244 MB),
 * indexed column or not. The same holds under Locale, where both sides pay a
 * Collator.
 *
 * php:sort-result and php:scan-sort differ only in who reads the rows: the
 * first still goes through the query layer, the second scans the table and
 * filters in PHP. On a narrow selection that difference is the dominant cost —
 * scanning 100k rows to return 100 is three times the price of asking the
 * query layer for them.
 *
 * Ordering targets are chosen to expose the index too: `title` is indexed, so
 * an unfiltered ORDER BY over it can be served from the index, while `sku` has
 * no index and always falls back to a scan-and-sort. sortLocale repeats the
 * indexed case under ComparisonModeEnum::Locale, where the byte-ordered index
 * no longer agrees with the collator — the PHP side then pays for a Collator of
 * its own, so the comparison stays honest.
 */
final class MappingBenchSort
{
    #[Bench(
        callables: [
            'api:array'       => [self::class, 'narrowArray'],
            'php:sort-result' => [self::class, 'narrowSortResult'],
            'php:scan-sort'   => [self::class, 'narrowScanSort'],
        ],
        calls: 2,
        iterations: 8,
    )]
    #[ExpectNoAssertions]
    public static function sortNarrow100(): int
    {
        return \count(iterator_to_array(
            self::dtoRows()
                ->where('bucket', '=', MappingFixture::narrowBucket())
                ->orderBy('title', 'asc')
                ->selectAll(),
        ));
    }

    public static function narrowArray(): int
    {
        return \count(
            self::arrayRows()
                ->where('bucket', '=', MappingFixture::narrowBucket())
                ->orderBy('title', 'asc')
                ->selectAllByArray(),
        );
    }

    public static function narrowSortResult(): int
    {
        $rows = self::arrayRows()
            ->where('bucket', '=', MappingFixture::narrowBucket())
            ->selectAllByArray();
        self::sortByTitle($rows);

        return \count($rows);
    }

    public static function narrowScanSort(): int
    {
        $rows = self::scanFiltered(
            'bucket',
            MappingFixture::narrowBucket(),
        );
        self::sortByTitle($rows);

        return \count($rows);
    }

    #[Bench(
        callables: [
            'api:array'       => [self::class, 'wideArray'],
            'php:sort-result' => [self::class, 'wideSortResult'],
            'php:scan-sort'   => [self::class, 'wideScanSort'],
        ],
        calls: 1,
        iterations: 8,
    )]
    #[ExpectNoAssertions]
    public static function sortWide10k(): int
    {
        return \count(iterator_to_array(
            self::dtoRows()
                ->where('decile', '=', 3)
                ->orderBy('title', 'asc')
                ->selectAll(),
        ));
    }

    public static function wideArray(): int
    {
        return \count(
            self::arrayRows()
                ->where('decile', '=', 3)
                ->orderBy('title', 'asc')
                ->selectAllByArray(),
        );
    }

    public static function wideSortResult(): int
    {
        $rows = self::arrayRows()
            ->where('decile', '=', 3)
            ->selectAllByArray();
        self::sortByTitle($rows);

        return \count($rows);
    }

    public static function wideScanSort(): int
    {
        $rows = self::scanFiltered('decile', 3);
        self::sortByTitle($rows);

        return \count($rows);
    }

    #[Bench(
        callables: [
            'api:array'       => [self::class, 'indexedArray'],
            'php:sort-result' => [self::class, 'indexedSortResult'],
            'php:scan-sort'   => [self::class, 'indexedScanSort'],
        ],
        calls: 1,
        iterations: 8,
    )]
    #[ExpectNoAssertions]
    public static function sortWholeTableIndexed(): int
    {
        return \count(iterator_to_array(
            self::dtoRows()
                ->orderBy('title', 'asc')
                ->limit(1000)
                ->selectAll(),
        ));
    }

    public static function indexedArray(): int
    {
        return \count(
            self::arrayRows()
                ->orderBy('title', 'asc')
                ->limit(1000)
                ->selectAllByArray(),
        );
    }

    public static function indexedSortResult(): int
    {
        $rows = self::arrayRows()->selectAllByArray();
        self::sortByTitle($rows);

        return \count(\array_slice($rows, 0, 1000));
    }

    public static function indexedScanSort(): int
    {
        $rows = MappingFixture::db()->readAll(MappingFixture::ROWS_TABLE);
        self::sortByTitle($rows);

        return \count(\array_slice($rows, 0, 1000));
    }

    #[Bench(
        callables: [
            'api:array'       => [self::class, 'unindexedArray'],
            'php:sort-result' => [self::class, 'unindexedSortResult'],
            'php:scan-sort'   => [self::class, 'unindexedScanSort'],
        ],
        calls: 1,
        iterations: 8,
    )]
    #[ExpectNoAssertions]
    public static function sortWholeTableUnindexed(): int
    {
        return \count(iterator_to_array(
            self::dtoRows()
                ->orderBy('sku', 'asc')
                ->limit(1000)
                ->selectAll(),
        ));
    }

    public static function unindexedArray(): int
    {
        return \count(
            self::arrayRows()
                ->orderBy('sku', 'asc')
                ->limit(1000)
                ->selectAllByArray(),
        );
    }

    public static function unindexedSortResult(): int
    {
        $rows = self::arrayRows()->selectAllByArray();
        self::sortBySku($rows);

        return \count(\array_slice($rows, 0, 1000));
    }

    public static function unindexedScanSort(): int
    {
        $rows = MappingFixture::db()->readAll(MappingFixture::ROWS_TABLE);
        self::sortBySku($rows);

        return \count(\array_slice($rows, 0, 1000));
    }

    #[Bench(
        callables: [
            'api:array'       => [self::class, 'localeArray'],
            'php:sort-result' => [self::class, 'localeSortResult'],
            'php:scan-sort'   => [self::class, 'localeScanSort'],
        ],
        calls: 1,
        iterations: 8,
    )]
    #[ExpectNoAssertions]
    public static function sortLocale(): int
    {
        return \count(iterator_to_array(
            self::dtoRows(ComparisonModeEnum::Locale)
                ->orderBy('title', 'asc')
                ->limit(1000)
                ->selectAll(),
        ));
    }

    public static function localeArray(): int
    {
        return \count(
            self::arrayRows(ComparisonModeEnum::Locale)
                ->orderBy('title', 'asc')
                ->limit(1000)
                ->selectAllByArray(),
        );
    }

    public static function localeSortResult(): int
    {
        $rows = self::arrayRows(ComparisonModeEnum::Locale)->selectAllByArray();
        self::sortByTitleCollated($rows);

        return \count(\array_slice($rows, 0, 1000));
    }

    public static function localeScanSort(): int
    {
        $rows = MappingFixture::db()->readAll(MappingFixture::ROWS_TABLE);
        self::sortByTitleCollated($rows);

        return \count(\array_slice($rows, 0, 1000));
    }

    #[Bench(
        callables: ['php:sort-result' => [self::class, 'limitSortResult']],
        warmup: 0,
        calls: 1,
        iterations: 8,
    )]
    #[ExpectNoAssertions]
    public static function sortLimit10(): int
    {
        return self::orderedSlice(10);
    }

    #[Bench(
        callables: ['php:sort-result' => [self::class, 'limitSortResult']],
        warmup: 0,
        calls: 1,
        iterations: 8,
    )]
    #[ExpectNoAssertions]
    public static function sortLimit100(): int
    {
        return self::orderedSlice(100);
    }

    #[Bench(
        callables: ['php:sort-result' => [self::class, 'limitSortResult']],
        warmup: 0,
        calls: 1,
        iterations: 8,
    )]
    #[ExpectNoAssertions]
    public static function sortLimit10k(): int
    {
        return self::orderedSlice(10000);
    }

    /**
     * The comparison side of the limit ladder: sorting the whole table in PHP
     * costs the same whatever the limit is, so it doubles as the flat line the
     * provider's curve is read against.
     */
    public static function limitSortResult(): int
    {
        $rows = self::arrayRows()->selectAllByArray();
        self::sortBySku($rows);

        return \count(\array_slice($rows, 0, 10));
    }

    /**
     * ORDER BY over an unindexed column with a limit — the engine has to sort
     * before it can slice, so this is where a partial (top-k) selection would
     * show up.
     */
    private static function orderedSlice(int $limit): int
    {
        return \count(
            self::arrayRows()
                ->orderBy('sku', 'asc')
                ->limit($limit)
                ->selectAllByArray(),
        );
    }

    /**
     * The rows table with MapRowFullDto bound and the comparison mode set.
     */
    private static function dtoRows(
        ComparisonModeEnum $mode = ComparisonModeEnum::Binary,
    ): JsonTable {
        return MappingFixture::bind(MapRowFullDto::class)
            ->setComparisonMode($mode)
            ->table(MappingFixture::ROWS_TABLE);
    }

    private static function arrayRows(
        ComparisonModeEnum $mode = ComparisonModeEnum::Binary,
    ): JsonTable {
        return MappingFixture::db()
            ->setComparisonMode($mode)
            ->table(MappingFixture::ROWS_TABLE);
    }

    /**
     * Whole-table read plus an equality filter in PHP — the query layer
     * bypassed, so only the read and the filter remain.
     *
     * @return array<int,array<string,null|scalar>>
     */
    private static function scanFiltered(string $field, int $value): array
    {
        $rows = MappingFixture::db()->readAll(MappingFixture::ROWS_TABLE);

        return array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r[$field] === $value,
        ));
    }

    /**
     * Byte-order sort, inlined. This is what the engine does through
     * ValueComparator::compare when the mode is Binary — same result, one
     * function call less per comparison.
     *
     * @param array<int,array<string,null|scalar>> $rows
     */
    private static function sortByTitle(array &$rows): void
    {
        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp(
                (string)$a['title'],
                (string)$b['title'],
            ),
        );
    }

    /**
     * @param array<int,array<string,null|scalar>> $rows
     */
    private static function sortBySku(array &$rows): void
    {
        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp(
                (string)$a['sku'],
                (string)$b['sku'],
            ),
        );
    }

    /**
     * Collator sort against the same locale the engine resolves, so the
     * Locale comparison measures dispatch overhead and not a cheaper
     * algorithm on the PHP side.
     *
     * @param array<int,array<string,null|scalar>> $rows
     */
    private static function sortByTitleCollated(array &$rows): void
    {
        $collator = new \Collator(\Locale::getDefault());

        usort(
            $rows,
            static fn (array $a, array $b): int => (int)$collator->compare(
                (string)$a['title'],
                (string)$b['title'],
            ),
        );
    }
}
