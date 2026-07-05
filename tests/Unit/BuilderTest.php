<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonFilter;
use AV\JsonProvider\Tests\Support\Fixture;
use Testo\Assert;
use Testo\Test;

/**
 * Tests for the builder infrastructure: JsonFilter, mutable + auto-reset,
 * setFilter, affectedRows, getLastInsertedId, getNextId.
 */
final class BuilderTest
{
    #[Test]
    public function filterIsEmptyByDefault(): void
    {
        $f = new JsonFilter();

        Assert::true($f->isEmpty());
        Assert::same($f->getConditions(), []);
    }

    #[Test]
    public function filterWhereReturnsNewInstance(): void
    {
        $a = new JsonFilter();
        $b = $a->where('id', '=', 1);

        Assert::notSame($b, $a);
        Assert::true($a->isEmpty());
        Assert::false($b->isEmpty());
        Assert::count($b->getConditions(), 1);
    }

    #[Test]
    public function filterChainAccumulatesConditions(): void
    {
        $f = (new JsonFilter())
            ->where('a', '=', 1)
            ->where('b', '>', 10)
            ->where('c', '=', 'x', not: true);

        Assert::count($f->getConditions(), 3);
    }

    #[Test]
    public function stateIsResetAfterTerminalSelect(): void
    {
        $tbl = Fixture::db()->table('categories');

        $tbl->where('id', '=', 1)->selectAllByArray();

        Assert::same($tbl->count(), 10);
    }

    #[Test]
    public function stateIsResetAfterTerminalDml(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $id = $tbl->insertByArray(['name' => 'AutoResetA', 'sort' => 100]);

        $totalAfterInsert = $tbl->count();
        Assert::same($totalAfterInsert, 11);

        $tbl->where('id', '=', $id)->updateByArray(['name' => 'AutoResetB']);

        $row = $tbl->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::same($row['name'], 'AutoResetB');

        $tbl->deleteById($id);
    }

    #[Test]
    public function setFilterAppliesAllConditions(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('products');

        $f = (new JsonFilter())
            ->where('category_id', '=', 1)
            ->where('in_stock', '=', true);

        $count = $tbl->setFilter($f)->count();

        $expected = $tbl
            ->where('category_id', '=', 1)
            ->where('in_stock', '=', true)
            ->count();

        Assert::same($count, $expected);
    }

    #[Test]
    public function setFilterReusableAcrossQueries(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('products');

        $f = (new JsonFilter())->where('category_id', '=', 1);

        $first = $tbl->setFilter($f)->count();
        $second = $tbl->setFilter($f)->count();

        Assert::same($second, $first);
    }

    #[Test]
    public function setFilterFollowedByWhereAppendsConditions(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('products');

        $f = (new JsonFilter())->where('category_id', '=', 1);

        $countAll = $tbl->setFilter($f)->count();

        $countActive = $tbl
            ->setFilter($f)
            ->where('in_stock', '=', true)
            ->count();

        Assert::int($countActive)->lessThanOrEqual($countAll);
    }

    #[Test]
    public function affectedRowsZeroBeforeAnyDml(): void
    {
        $tbl = Fixture::db()->table('categories');

        Assert::same($tbl->affectedRows(), 0);
    }

    #[Test]
    public function affectedRowsAfterInsertIsOne(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $id = $tbl->insertByArray(['name' => 'AffIns', 'sort' => 1]);

        Assert::same($tbl->affectedRows(), 1);

        $tbl->deleteById($id);
    }

    #[Test]
    public function affectedRowsAfterBulkUpdate(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $idA = $tbl->insertByArray(['name' => 'AffUpdA', 'sort' => 5000]);
        $idB = $tbl->insertByArray(['name' => 'AffUpdB', 'sort' => 5000]);

        $tbl->where('sort', '=', 5000)->updateByArray(['sort' => 5001]);

        Assert::same($tbl->affectedRows(), 2);

        $tbl->deleteById($idA);
        $tbl->deleteById($idB);
    }

    #[Test]
    public function affectedRowsZeroOnEmptyMatchUpdate(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $tbl->where('sort', '=', -42)->updateByArray(['name' => 'NoMatch']);

        Assert::same($tbl->affectedRows(), 0);
    }

    #[Test]
    public function affectedRowsAfterDelete(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $idA = $tbl->insertByArray(['name' => 'AffDelA', 'sort' => 6000]);
        $idB = $tbl->insertByArray(['name' => 'AffDelB', 'sort' => 6000]);
        $idC = $tbl->insertByArray(['name' => 'AffDelC', 'sort' => 6000]);

        $tbl->where('sort', '=', 6000)->delete();

        Assert::same($tbl->affectedRows(), 3);

        $tbl->deleteById($idA);
        $tbl->deleteById($idB);
        $tbl->deleteById($idC);
    }

    #[Test]
    public function affectedRowsNotChangedByReadOps(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $id = $tbl->insertByArray(['name' => 'AffRead', 'sort' => 7000]);
        Assert::same($tbl->affectedRows(), 1);

        $tbl->count();
        $tbl->selectAllByArray();
        $tbl->where('id', '=', $id)->selectOneByArray();
        $tbl->getLastInsertedId();
        $tbl->getNextId();
        $tbl->exists();

        Assert::same($tbl->affectedRows(), 1);

        $tbl->deleteById($id);
    }

    #[Test]
    public function getLastInsertedIdReturnsLastAllocatedId(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $id1 = $tbl->insertByArray(['name' => 'LastA', 'sort' => 8000]);
        $id2 = $tbl->insertByArray(['name' => 'LastB', 'sort' => 8001]);

        Assert::int($id2)->greaterThan($id1);
        Assert::same($tbl->getLastInsertedId(), $id2);

        $tbl->deleteById($id1);
        $tbl->deleteById($id2);
    }

    #[Test]
    public function getLastInsertedIdDoesNotRollBackAfterDelete(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $id1 = $tbl->insertByArray(['name' => 'KeepA', 'sort' => 8100]);
        $id2 = $tbl->insertByArray(['name' => 'KeepB', 'sort' => 8101]);

        $before = $tbl->getLastInsertedId();
        Assert::same($before, $id2);

        $tbl->deleteById($id2);

        Assert::same($tbl->getLastInsertedId(), $before);

        $tbl->deleteById($id1);
    }

    #[Test]
    public function getNextIdReturnsLastInsertedIdPlusOne(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $last = $tbl->getLastInsertedId();
        $predicted = $tbl->getNextId();

        Assert::same($predicted, $last + 1);

        $actualNew = $tbl->insertByArray(
            ['name' => 'NextProbe', 'sort' => 8200]
        );
        Assert::same($actualNew, $predicted);

        $tbl->deleteById($actualNew);
    }

    #[Test]
    public function getLastInsertedIdIgnoresAccumulatedQueryState(): void
    {
        $db = Fixture::db();

        $withFilter = $db->table('categories')
            ->where('id', '=', 999999)
            ->getLastInsertedId();
        $clean = $db->table('categories')->getLastInsertedId();

        Assert::same($withFilter, $clean);
    }
}
