<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Tests\Support\Fixture;
use Testo\Assert;
use Testo\Test;

/**
 * Tests for selection: where (all operators), orderBy, limit, offset,
 * isDistinct, selectColumn. Read-only — the fixture is not mutated.
 */
final class QueryTest
{
    #[Test]
    public function selectAllReturnsAllProducts(): void
    {
        $all = Fixture::db()->table('products')->selectAllByArray();

        Assert::count($all, 100);
    }

    #[Test]
    public function selectAllReturnsAllCategories(): void
    {
        $all = Fixture::db()->table('categories')->selectAllByArray();

        Assert::count($all, 10);
    }

    #[Test]
    public function selectOneReturnsFirstMatch(): void
    {
        $record = Fixture::db()->table('products')
            ->where('name', '=', 'Product 1')
            ->selectOneByArray();

        Assert::notNull($record);
        Assert::same($record['name'], 'Product 1');
    }

    #[Test]
    public function selectOneReturnsNullWhenNoMatch(): void
    {
        $record = Fixture::db()->table('products')
            ->where('name', '=', 'Nonexistent')
            ->selectOneByArray();

        Assert::null($record);
    }

    #[Test]
    public function whereEqFiltersByCategory(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', '=', 1)
            ->selectAllByArray();

        Assert::count($results, 10);

        foreach ($results as $r) {
            Assert::same($r['category_id'], 1);
        }
    }

    #[Test]
    public function whereEqBool(): void
    {
        $inStock = Fixture::db()->table('products')
            ->where('in_stock', '=', true)
            ->selectAllByArray();

        Assert::count($inStock, 67);
    }

    #[Test]
    public function whereNotEqFilters(): void
    {
        $others = Fixture::db()->table('products')
            ->where('category_id', '=', 1, true)
            ->selectAllByArray();

        Assert::count($others, 90);

        foreach ($others as $r) {
            Assert::notSame($r['category_id'], 1);
        }
    }

    #[Test]
    public function whereGtFilters(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', '>', 5)
            ->selectAllByArray();

        Assert::count($results, 50);

        foreach ($results as $r) {
            Assert::int($r['category_id'])->greaterThan(5);
        }
    }

    #[Test]
    public function whereGteFilters(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', '>=', 5)
            ->selectAllByArray();

        Assert::count($results, 60);

        foreach ($results as $r) {
            Assert::int($r['category_id'])->greaterThanOrEqual(5);
        }
    }

    #[Test]
    public function whereLtFilters(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', '<', 3)
            ->selectAllByArray();

        Assert::count($results, 20);

        foreach ($results as $r) {
            Assert::int($r['category_id'])->lessThan(3);
        }
    }

    #[Test]
    public function whereLteFilters(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', '<=', 2)
            ->selectAllByArray();

        Assert::count($results, 20);
    }

    #[Test]
    public function whereBetweenFilters(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', 'BETWEEN', [3, 5])
            ->selectAllByArray();

        Assert::count($results, 30);

        foreach ($results as $r) {
            Assert::int($r['category_id'])
                ->greaterThanOrEqual(3)
                ->lessThanOrEqual(5);
        }
    }

    #[Test]
    public function whereLikePrefix(): void
    {
        $results = Fixture::db()->table('products')
            ->where('name', 'LIKE', 'Product 1%')
            ->selectAllByArray();

        Assert::count($results, 12);
    }

    #[Test]
    public function whereLikeSuffix(): void
    {
        $results = Fixture::db()->table('products')
            ->where('name', 'LIKE', '%0')
            ->selectAllByArray();

        Assert::count($results, 10);
    }

    #[Test]
    public function whereInFiltersByMultipleValues(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', 'IN', [1, 3, 5])
            ->selectAllByArray();

        Assert::count($results, 30);

        foreach ($results as $r) {
            Assert::contains([1, 3, 5], $r['category_id']);
        }
    }

    #[Test]
    public function whereInSingleValue(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', 'IN', [7])
            ->selectAllByArray();

        Assert::count($results, 10);

        foreach ($results as $r) {
            Assert::same($r['category_id'], 7);
        }
    }

    #[Test]
    public function whereInEmptyArrayReturnsNothing(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', 'IN', [])
            ->selectAllByArray();

        Assert::count($results, 0);
    }

    #[Test]
    public function whereNotInExcludesValues(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', 'IN', [1, 2], true)
            ->selectAllByArray();

        Assert::count($results, 80);

        foreach ($results as $r) {
            Assert::iterable([1, 2])->notContains($r['category_id']);
        }
    }

    #[Test]
    public function whereInWithStrings(): void
    {
        $results = Fixture::db()->table('tags')
            ->where('label', 'IN', ['sale', 'hot'])
            ->selectAllByArray();

        Assert::count($results, 40);

        foreach ($results as $r) {
            Assert::contains(['sale', 'hot'], $r['label']);
        }
    }

    #[Test]
    public function whereInCombinedWithOtherConditions(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', 'IN', [1, 2])
            ->where('in_stock', '=', true)
            ->selectAllByArray();

        foreach ($results as $r) {
            Assert::contains([1, 2], $r['category_id']);
            Assert::true((bool)$r['in_stock']);
        }

        Assert::int(\count($results))->greaterThan(0);
        Assert::int(\count($results))->lessThan(20);
    }

    #[Test]
    public function whereInCount(): void
    {
        $count = Fixture::db()->table('products')
            ->where('category_id', 'IN', [4, 5, 6])
            ->count();

        Assert::same($count, 30);
    }

    #[Test]
    public function whereInSelectColumn(): void
    {
        $names = Fixture::db()->table('categories')
            ->where('id', 'IN', [1, 5, 10])
            ->selectColumn('name');

        Assert::count($names, 3);
        Assert::contains($names, 'Electronics');
        Assert::contains($names, 'Toys');
        Assert::contains($names, 'Automotive');
    }

    #[Test]
    public function multipleWhereConditionsAreAnd(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', '=', 1)
            ->where('in_stock', '=', true)
            ->selectAllByArray();

        foreach ($results as $r) {
            Assert::same($r['category_id'], 1);
            Assert::true((bool)$r['in_stock']);
        }

        Assert::count($results, 7);
    }

    #[Test]
    public function orderByAsc(): void
    {
        $results = Fixture::db()->table('categories')
            ->orderBy('sort', 'asc')
            ->selectAllByArray();

        $sorts = array_column($results, 'sort');
        $sorted = $sorts;
        sort($sorted);

        Assert::same($sorts, $sorted);
    }

    #[Test]
    public function orderByDesc(): void
    {
        $results = Fixture::db()->table('categories')
            ->orderBy('sort', 'desc')
            ->selectAllByArray();

        $sorts = array_column($results, 'sort');
        $sorted = $sorts;
        rsort($sorted);

        Assert::same($sorts, $sorted);
    }

    #[Test]
    public function orderByPriceDesc(): void
    {
        $results = Fixture::db()->table('products')
            ->orderBy('price', 'desc')
            ->selectAllByArray();

        $prices = array_map(
            static fn (array $r): float => (float)$r['price'],
            $results,
        );

        for ($i = 0; $i < \count($prices) - 1; $i++) {
            Assert::float($prices[$i])->greaterThanOrEqual($prices[$i + 1]);
        }
    }

    #[Test]
    public function orderByMultipleFields(): void
    {
        $results = Fixture::db()->table('products')
            ->orderBy('category_id', 'asc')
            ->orderBy('price', 'desc')
            ->selectAllByArray();

        $prev = null;

        foreach ($results as $r) {
            if ($prev !== null && $r['category_id'] === $prev['category_id']) {
                Assert::float((float)$prev['price'])
                    ->greaterThanOrEqual((float)$r['price']);
            }

            $prev = $r;
        }
    }

    #[Test]
    public function limitRestrictsCount(): void
    {
        $results = Fixture::db()->table('products')
            ->limit(10)->selectAllByArray();

        Assert::count($results, 10);
    }

    #[Test]
    public function offsetSkipsRecords(): void
    {
        $all = Fixture::db()->table('products')->selectAllByArray();
        $paged = Fixture::db()->table('products')
            ->offset(5)
            ->limit(5)
            ->selectAllByArray();

        Assert::count($paged, 5);
        Assert::same($paged[0]['id'], $all[5]['id']);
    }

    #[Test]
    public function limitOffsetPagination(): void
    {
        $page1 = Fixture::db()->table('products')
            ->limit(10)
            ->offset(0)
            ->selectAllByArray();
        $page2 = Fixture::db()->table('products')
            ->limit(10)
            ->offset(10)
            ->selectAllByArray();

        Assert::count($page1, 10);
        Assert::count($page2, 10);

        $ids1 = array_column($page1, 'id');
        $ids2 = array_column($page2, 'id');

        Assert::blank(array_intersect($ids1, $ids2));
    }

    #[Test]
    public function paginationWithIndexedOrderBy(): void
    {
        $all = Fixture::db()->table('products')
            ->orderBy('price', 'desc')
            ->selectAllByArray();

        $page2 = Fixture::db()->table('products')
            ->orderBy('price', 'desc')
            ->offset(10)
            ->limit(10)
            ->selectAllByArray();

        Assert::count($page2, 10);
        Assert::same($page2[0]['id'], $all[10]['id']);
        Assert::same($page2[9]['id'], $all[19]['id']);
    }

    #[Test]
    public function paginationWithIndexedFilter(): void
    {
        $allCat1 = Fixture::db()->table('products')
            ->where('category_id', '=', 1)
            ->selectAllByArray();

        $page = Fixture::db()->table('products')
            ->where('category_id', '=', 1)
            ->offset(3)
            ->limit(5)
            ->selectAllByArray();

        Assert::count($page, 5);
        Assert::same($page[0]['id'], $allCat1[3]['id']);
    }

    #[Test]
    public function paginationWithIndexedOrderByAndFilter(): void
    {
        $allFiltered = Fixture::db()->table('products')
            ->where('category_id', '=', 2)
            ->orderBy('price', 'desc')
            ->selectAllByArray();

        $page = Fixture::db()->table('products')
            ->where('category_id', '=', 2)
            ->orderBy('price', 'desc')
            ->offset(2)
            ->limit(3)
            ->selectAllByArray();

        Assert::count($page, 3);
        Assert::same($page[0]['id'], $allFiltered[2]['id']);
        Assert::same($page[2]['id'], $allFiltered[4]['id']);
    }

    #[Test]
    public function offsetBeyondResultsReturnsEmpty(): void
    {
        $results = Fixture::db()->table('products')
            ->where('category_id', '=', 1)
            ->offset(100)
            ->limit(10)
            ->selectAllByArray();

        Assert::count($results, 0);
    }

    #[Test]
    public function limitWithoutOffsetIsFirstPage(): void
    {
        $all = Fixture::db()->table('products')
            ->orderBy('price', 'desc')
            ->selectAllByArray();

        $first5 = Fixture::db()->table('products')
            ->orderBy('price', 'desc')
            ->limit(5)
            ->selectAllByArray();

        Assert::count($first5, 5);
        Assert::same($first5[0]['id'], $all[0]['id']);
        Assert::same($first5[4]['id'], $all[4]['id']);
    }

    #[Test]
    public function offsetWithoutLimitSelectsAll(): void
    {
        $all = Fixture::db()->table('products')->selectAllByArray();

        $fromOffset = Fixture::db()->table('products')
            ->offset(95)
            ->selectAllByArray();

        Assert::count($fromOffset, 5);
        Assert::same($fromOffset[0]['id'], $all[95]['id']);
    }

    #[Test]
    public function selectColumnReturnsScalarValues(): void
    {
        $names = Fixture::db()->table('categories')->selectColumn('name');

        Assert::count($names, 10);

        foreach ($names as $name) {
            Assert::string($name);
        }
    }

    #[Test]
    public function selectColumnWithFilter(): void
    {
        $ids = Fixture::db()->table('products')
            ->where('category_id', '=', 2)
            ->selectColumn('id');

        Assert::count($ids, 10);

        foreach ($ids as $id) {
            Assert::int($id);
        }
    }

    #[Test]
    public function selectColumnWithLimit(): void
    {
        $names = Fixture::db()->table('products')
            ->limit(5)
            ->selectColumn('name');

        Assert::count($names, 5);
    }

    #[Test]
    public function isDistinctDeduplicates(): void
    {
        $distinct = Fixture::db()->table('products')
            ->isDistinct('category_id')
            ->selectAllByArray();

        Assert::count($distinct, 10);

        $categoryIds = array_column($distinct, 'category_id');
        Assert::count(array_unique($categoryIds), 10);
    }

    #[Test]
    public function isDistinctMultipleFields(): void
    {
        $distinct = Fixture::db()->table('tags')
            ->isDistinct('label')
            ->selectAllByArray();

        Assert::count($distinct, 5);
    }

    #[Test]
    public function isDistinctCount(): void
    {
        $count = Fixture::db()->table('products')
            ->isDistinct('category_id')
            ->count();

        Assert::same($count, 10);
    }

    #[Test]
    public function countAllProducts(): void
    {
        Assert::same(Fixture::db()->table('products')->count(), 100);
    }

    #[Test]
    public function countWithFilter(): void
    {
        $count = Fixture::db()->table('products')
            ->where('category_id', '=', 3)
            ->count();

        Assert::same($count, 10);
    }

    #[Test]
    public function existsReturnsTrue(): void
    {
        $exists = Fixture::db()->table('products')
            ->where('name', '=', 'Product 50')
            ->exists();

        Assert::true($exists);
    }

    #[Test]
    public function existsReturnsFalse(): void
    {
        $exists = Fixture::db()->table('products')
            ->where('name', '=', 'NoSuchProduct')
            ->exists();

        Assert::false($exists);
    }

    #[Test]
    public function fixtureMatchesStoredProducts(): void
    {
        $fixture = Fixture::products();
        $stored = Fixture::db()->table('products')
            ->orderBy('id', 'asc')
            ->selectAllByArray();

        Assert::count($stored, \count($fixture));

        usort(
            $fixture,
            static fn (array $a, array $b): int => (int)$a['id']
                <=> (int)$b['id'],
        );

        foreach ($stored as $i => $record) {
            Assert::same($record['id'], $fixture[$i]['id']);
            Assert::same($record['name'], $fixture[$i]['name']);
            Assert::same($record['category_id'], $fixture[$i]['category_id']);
        }
    }

    #[Test]
    public function fixtureMatchesStoredCategories(): void
    {
        $fixture = Fixture::categories();
        $stored = Fixture::db()->table('categories')
            ->orderBy('id', 'asc')
            ->selectAllByArray();

        Assert::count($stored, \count($fixture));

        usort(
            $fixture,
            static fn (array $a, array $b): int => (int)$a['id']
                <=> (int)$b['id'],
        );

        foreach ($stored as $i => $record) {
            Assert::same($record['id'], $fixture[$i]['id']);
            Assert::same($record['name'], $fixture[$i]['name']);
            Assert::same($record['sort'], $fixture[$i]['sort']);
        }
    }
}
