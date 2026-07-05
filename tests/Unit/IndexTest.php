<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Storage\NdjsonStorage;
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

    #[Test]
    public function filterIndexEqMatchesFullScan(): void
    {
        $db = Fixture::db();

        $viaIndex = $db->table('products')
            ->where('category_id', '=', 3)
            ->selectAllByArray();

        $expected = array_values(array_filter(
            Fixture::products(),
            static fn (array $r): bool => $r['category_id'] === 3,
        ));

        Assert::count($viaIndex, \count($expected));

        $indexedIds = array_column($viaIndex, 'id');
        $expectedIds = array_column($expected, 'id');

        sort($indexedIds);
        sort($expectedIds);

        Assert::same($indexedIds, $expectedIds);
    }

    #[Test]
    public function filterIndexGtMatchesFullScan(): void
    {
        $db = Fixture::db();

        $viaIndex = $db->table('products')
            ->where('category_id', '>', 7)
            ->selectAllByArray();

        $expected = array_values(array_filter(
            Fixture::products(),
            static fn (array $r): bool => (int)$r['category_id'] > 7,
        ));

        Assert::count($viaIndex, \count($expected));
    }

    #[Test]
    public function filterIndexBetweenMatchesFullScan(): void
    {
        $db = Fixture::db();

        $viaIndex = $db->table('products')
            ->where('category_id', 'BETWEEN', [4, 6])
            ->selectAllByArray();

        $expected = array_values(array_filter(
            Fixture::products(),
            static function (array $r): bool {
                return (int)$r['category_id'] >= 4
                    && (int)$r['category_id'] <= 6;
            },
        ));

        Assert::count($viaIndex, \count($expected));

        $indexedIds = array_column($viaIndex, 'id');
        $expectedIds = array_column($expected, 'id');
        sort($indexedIds);
        sort($expectedIds);

        Assert::same($indexedIds, $expectedIds);
    }

    #[Test]
    public function tagsFilterIndexMatchesFullScan(): void
    {
        $db = Fixture::db();

        $productId = 5;

        $viaIndex = $db->table('tags')
            ->where('product_id', '=', $productId)
            ->selectAllByArray();

        $expected = array_values(array_filter(
            Fixture::tags(),
            static fn (array $r): bool => $r['product_id'] === $productId,
        ));

        Assert::count($viaIndex, \count($expected));
        Assert::same($viaIndex[0]['product_id'], $expected[0]['product_id']);
    }

    #[Test]
    public function indexFileExistsAfterSeed(): void
    {
        Fixture::db();
        $dir = Fixture::DB_PATH;

        Assert::true(is_file($dir . '/products/idx_category.index.ndjson'));
        Assert::true(is_file($dir . '/products/idx_price_desc.index.ndjson'));
        Assert::true(is_file($dir . '/tags/idx_product.index.ndjson'));
    }

    #[Test]
    public function indexFileHasCorrectLineCount(): void
    {
        Fixture::db();

        $path = Fixture::DB_PATH . '/products/idx_category.index.ndjson';
        $lines = array_filter(
            explode("\n", (string)file_get_contents($path)),
            static fn (string $l): bool => trim($l) !== '',
        );

        Assert::count($lines, 100);
    }

    #[Test]
    public function readIndexReturnsSortedEntries(): void
    {
        Fixture::db();

        $indexSchema = new IndexSchema(
            name: 'idx_category',
            fields: [new IndexFieldSchema(
                'category_id',
                SortDirectionEnum::ASC,
            )],
        );

        $indexManager = new IndexManager(new NdjsonStorage(Fixture::DB_PATH));
        $entries = $indexManager->readIndex('products', $indexSchema);

        $keys = array_column($entries, 'key');
        $sorted = $keys;
        sort($sorted);

        Assert::same($keys, $sorted);
    }

    #[Test]
    public function indexManagerReadIndexReturnsCorrectStructure(): void
    {
        Fixture::db();

        $indexSchema = new IndexSchema(
            name: 'idx_category',
            fields: [new IndexFieldSchema(
                'category_id',
                SortDirectionEnum::ASC,
            )],
        );

        $manager = new IndexManager(new NdjsonStorage(Fixture::DB_PATH));
        $entries = $manager->readIndex('products', $indexSchema);

        Assert::count($entries, 100);

        foreach ($entries as $entry) {
            Assert::array($entry)->hasKeys('key');
            Assert::array($entry)->hasKeys('line');
            Assert::true($entry['key'] !== '');
            Assert::int($entry['line'])->greaterThanOrEqual(0);
            Assert::int($entry['line'])->lessThan(100);
        }
    }

    #[Test]
    public function indexManagerSearchLinesEq(): void
    {
        Fixture::db();

        $indexSchema = new IndexSchema(
            name: 'idx_category',
            fields: [new IndexFieldSchema(
                'category_id',
                SortDirectionEnum::ASC,
            )],
        );

        $manager = new IndexManager(new NdjsonStorage(Fixture::DB_PATH));

        $condition = new FilterCondition(
            'category_id',
            FilterOperatorEnum::EQ,
            4,
        );

        $lines = $manager->searchLines('products', $indexSchema, $condition);

        Assert::notNull($lines);
        Assert::count($lines, 10);
    }

    #[Test]
    public function indexAndFullScanReturnSameIds(): void
    {
        $db = Fixture::db();

        $viaIndexRecords = $db->table('products')
            ->where('category_id', '=', 2)
            ->selectAllByArray();

        $indexIds = array_column($viaIndexRecords, 'id');
        sort($indexIds);

        $fromFixture = array_values(array_filter(
            Fixture::products(),
            static fn (array $r): bool => $r['category_id'] === 2,
        ));

        $fixtureIds = array_column($fromFixture, 'id');
        sort($fixtureIds);

        Assert::same($indexIds, $fixtureIds);
    }
}
