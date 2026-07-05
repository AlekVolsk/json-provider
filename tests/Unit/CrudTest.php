<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Tests\Support\Fixture;
use Testo\Assert;
use Testo\Test;

/**
 * Tests for CRUD operations: insert, update, delete.
 * Use the 'categories' table (10 fixture rows).
 * Changes are isolated: new rows are added/removed within each test.
 */
final class CrudTest
{
    #[Test]
    public function insertReturnsNewId(): void
    {
        $db = Fixture::db();

        $id = $db->table('categories')
            ->insertByArray(['name' => 'TempCrud', 'sort' => 999]);

        Assert::int($id)->greaterThan(0);

        $row = $db->table('categories')
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::same($row['name'], 'TempCrud');
        Assert::same($row['sort'], 999);

        $db->table('categories')->deleteById($id);
    }

    #[Test]
    public function insertAutoIncrementIsGreaterThanPrevious(): void
    {
        $db = Fixture::db();

        $idA = $db->table('categories')
            ->insertByArray(['name' => 'TempA', 'sort' => 1]);
        $idB = $db->table('categories')
            ->insertByArray(['name' => 'TempB', 'sort' => 2]);

        Assert::int($idB)->greaterThan($idA);

        $db->table('categories')->deleteById($idA);
        $db->table('categories')->deleteById($idB);
    }

    #[Test]
    public function insertedRecordIsPersisted(): void
    {
        $db = Fixture::db();

        $id = $db->table('products')->insertByArray([
            'name'        => 'PersistTest',
            'category_id' => 1,
            'price'       => 42.5,
            'in_stock'    => true,
        ]);

        $db->invalidateCache('products');

        $found = $db->table('products')
            ->where('id', '=', $id)->selectOneByArray();

        Assert::notNull($found);
        Assert::same($found['name'], 'PersistTest');
        Assert::same($found['price'], 42.5);
        Assert::true($found['in_stock']);

        $db->table('products')->deleteById($id);
    }

    #[Test]
    public function insertCountIncreases(): void
    {
        $db = Fixture::db();

        $before = $db->table('categories')->count();

        $id = $db->table('categories')
            ->insertByArray(['name' => 'CountTest', 'sort' => 500]);

        $after = $db->table('categories')->count();

        Assert::same($after, $before + 1);

        $db->table('categories')->deleteById($id);
    }

    #[Test]
    public function updateByIdChangesField(): void
    {
        $db = Fixture::db();

        $id = $db->table('categories')
            ->insertByArray(['name' => 'BeforeUpdate', 'sort' => 100]);

        $ok = $db->table('categories')
            ->updateByIdByArray($id, ['name' => 'AfterUpdate']);

        Assert::true($ok);

        $row = $db->table('categories')
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::same($row['name'], 'AfterUpdate');
        Assert::same($row['sort'], 100);

        $db->table('categories')->deleteById($id);
    }

    #[Test]
    public function updateByIdDoesNotChangeId(): void
    {
        $db = Fixture::db();

        $id = $db->table('categories')
            ->insertByArray(['name' => 'IdLock', 'sort' => 200]);

        $ok = $db->table('categories')
            ->updateByIdByArray($id, ['id' => 9999, 'name' => 'IdLockAfter']);

        Assert::true($ok);

        $row = $db->table('categories')
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::same($row['id'], $id);
        Assert::same($row['name'], 'IdLockAfter');

        $db->table('categories')->deleteById($id);
    }

    #[Test]
    public function updateByIdNonExistentReturnsTrue(): void
    {
        $ok = Fixture::db()
            ->table('categories')
            ->updateByIdByArray(999999, ['name' => 'Ghost']);

        Assert::true($ok);
    }

    #[Test]
    public function updatePersisted(): void
    {
        $db = Fixture::db();

        $id = $db->table('categories')
            ->insertByArray(['name' => 'PersistBefore', 'sort' => 300]);

        $db->table('categories')
            ->updateByIdByArray($id, ['name' => 'PersistAfter']);
        $db->invalidateCache('categories');

        $found = $db->table('categories')
            ->where('id', '=', $id)->selectOneByArray();

        Assert::notNull($found);
        Assert::same($found['name'], 'PersistAfter');

        $db->table('categories')->deleteById($id);
    }

    #[Test]
    public function bulkUpdateByConditionAffectsAllMatching(): void
    {
        $db = Fixture::db();

        $idA = $db->table('categories')
            ->insertByArray(['name' => 'BulkA', 'sort' => 555]);
        $idB = $db->table('categories')
            ->insertByArray(['name' => 'BulkB', 'sort' => 555]);
        $idC = $db->table('categories')
            ->insertByArray(['name' => 'BulkC', 'sort' => 556]);

        $ok = $db->table('categories')
            ->where('sort', '=', 555)
            ->updateByArray(['sort' => 777]);

        Assert::true($ok);

        $db->invalidateCache('categories');

        $reA = $db->table('categories')
            ->where('id', '=', $idA)->selectOneByArray();
        $reB = $db->table('categories')
            ->where('id', '=', $idB)->selectOneByArray();
        $reC = $db->table('categories')
            ->where('id', '=', $idC)->selectOneByArray();

        Assert::notNull($reA);
        Assert::notNull($reB);
        Assert::notNull($reC);
        Assert::same($reA['sort'], 777);
        Assert::same($reB['sort'], 777);
        Assert::same($reC['sort'], 556);

        $db->table('categories')->deleteById($idA);
        $db->table('categories')->deleteById($idB);
        $db->table('categories')->deleteById($idC);
    }

    #[Test]
    public function bulkUpdateNoMatchReturnsTrue(): void
    {
        $ok = Fixture::db()
            ->table('categories')
            ->where('sort', '=', -1)
            ->updateByArray(['name' => 'NoMatch']);

        Assert::true($ok);
    }

    #[Test]
    public function deleteByConditionRemovesRecords(): void
    {
        $db = Fixture::db();

        $idA = $db->table('categories')
            ->insertByArray(['name' => 'DelA', 'sort' => 700]);
        $idB = $db->table('categories')
            ->insertByArray(['name' => 'DelB', 'sort' => 700]);

        $ok = $db->table('categories')->where('sort', '=', 700)->delete();

        Assert::true($ok);

        $remaining = $db->table('categories')
            ->where('id', '=', $idA)
            ->count();

        Assert::same($remaining, 0);

        $db->table('categories')->deleteById($idB);
    }

    #[Test]
    public function deleteNonExistentReturnsTrue(): void
    {
        $ok = Fixture::db()
            ->table('categories')
            ->where('id', '=', 999999)
            ->delete();

        Assert::true($ok);
    }

    #[Test]
    public function deleteCountDecreases(): void
    {
        $db = Fixture::db();

        $id = $db->table('categories')
            ->insertByArray(['name' => 'CountDel', 'sort' => 800]);
        $before = $db->table('categories')->count();

        $db->table('categories')->deleteById($id);

        $after = $db->table('categories')->count();

        Assert::same($after, $before - 1);
    }

    #[Test]
    public function deletePersisted(): void
    {
        $db = Fixture::db();

        $id = $db->table('categories')
            ->insertByArray(['name' => 'PersistDel', 'sort' => 900]);

        $db->table('categories')->deleteById($id);
        $db->invalidateCache('categories');

        $found = $db->table('categories')
            ->where('id', '=', $id)->selectOneByArray();

        Assert::null($found);
    }

    #[Test]
    public function deleteByIdRemovesSingleRecord(): void
    {
        $db = Fixture::db();

        $id = $db->table('categories')
            ->insertByArray(['name' => 'DelById', 'sort' => 950]);

        $ok = $db->table('categories')->deleteById($id);

        Assert::true($ok);

        $found = $db->table('categories')
            ->where('id', '=', $id)->selectOneByArray();
        Assert::null($found);
    }

    #[Test]
    public function deleteByIdNonExistentReturnsTrue(): void
    {
        $ok = Fixture::db()->table('categories')->deleteById(999999);

        Assert::true($ok);
    }
}
