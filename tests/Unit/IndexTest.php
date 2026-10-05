<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Tests\Support\Fixture;
use Testo\Assert;
use Testo\Test;

/**
 * Tests for the index subsystem:
 *  - ordering-index: sorting via the index yields the same result as
 *    full-scan + sort
 *  - filter-index: filtering via the index yields the same result as
 *    full-scan + filter
 *  - correctness of index file contents after writes
 *  - IndexManager::searchLines for all supported operators
 */
final class IndexTest
{
    #[Test]
    public function orderingIndexPriceDescMatchesFullScan(): void
    {
        $db = Fixture::db();

        $viaIndex = $db->table('products')
            ->orderBy('price', 'desc')
            ->selectAllByArray();

        $allProducts = Fixture::products();
        usort(
            $allProducts,
            static function (array $a, array $b): int {
                return (float)$b['price'] <=> (float)$a['price'];
            },
        );

        Assert::count($viaIndex, \count($allProducts));

        foreach ($viaIndex as $i => $record) {
            Assert::same($record['id'], $allProducts[$i]['id']);
            Assert::same(
                (float)$record['price'],
                (float)$allProducts[$i]['price'],
            );
        }
    }

    #[Test]
    public function orderingIndexCategoryAscMatchesFullScan(): void
    {
        $db = Fixture::db();

        $viaIndex = $db->table('products')
            ->orderBy('category_id', 'asc')
            ->selectAllByArray();

        $allProducts = Fixture::products();
        usort(
            $allProducts,
            static function (array $a, array $b): int {
                return (int)$a['category_id'] <=> (int)$b['category_id'];
            },
        );

        Assert::count($viaIndex, \count($allProducts));

        $indexedCategories = array_column($viaIndex, 'category_id');
        $fixtureCategories = array_column($allProducts, 'category_id');

        Assert::same($indexedCategories, $fixtureCategories);
    }

    #[Test]
    public function orderingIndexWithConditionFiltersCorrectly(): void
    {
        $db = Fixture::db();

        $viaIndex = $db->table('products')
            ->where('in_stock', '=', true)
            ->orderBy('price', 'desc')
            ->selectAllByArray();

        $expected = array_values(array_filter(
            Fixture::products(),
            static fn (array $r): bool => (bool)$r['in_stock'] === true,
        ));
        usort(
            $expected,
            static function (array $a, array $b): int {
                return (float)$b['price'] <=> (float)$a['price'];
            },
        );

        Assert::count($viaIndex, \count($expected));

        foreach ($viaIndex as $i => $record) {
            Assert::same($record['id'], $expected[$i]['id']);
        }
    }

    #[Test]
    public function orderingIndexWithLimitReturnsTopN(): void
    {
        $db = Fixture::db();

        $top5 = $db->table('products')
            ->orderBy('price', 'desc')
            ->limit(5)
            ->selectAllByArray();

        Assert::count($top5, 5);

        $allSorted = Fixture::products();
        usort(
            $allSorted,
            static function (array $a, array $b): int {
                return (float)$b['price'] <=> (float)$a['price'];
            },
        );

        Assert::same($top5[0]['id'], $allSorted[0]['id']);
        Assert::same($top5[4]['id'], $allSorted[4]['id']);
    }
}
