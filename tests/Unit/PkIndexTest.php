<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Tests\Support\Fixture;
use Testo\Assert;
use Testo\Test;

/**
 * Tests for the primary-key index.
 *
 * Asserts:
 *  - the PK index file is auto-created at createTable time;
 *  - the file is populated on insert;
 *  - select by id returns the correct row via the PK index;
 *  - the PK index survives a full table rewrite (rebuild on writeAll).
 */
final class PkIndexTest
{
    #[Test]
    public function pkIndexFileExistsAfterCreateTable(): void
    {
        Fixture::boot();

        Assert::true(is_file(Fixture::DB_PATH . '/categories/pk.index.ndjson'));
        Assert::true(is_file(Fixture::DB_PATH . '/products/pk.index.ndjson'));
        Assert::true(is_file(Fixture::DB_PATH . '/tags/pk.index.ndjson'));
    }

    #[Test]
    public function pkIndexIsFirstInPersistedSchema(): void
    {
        Fixture::boot();

        $storage = new JsonStorage(Fixture::DB_PATH);
        $schema = $storage->read('information_schema.json');

        /**
         * @var array{tables: array<string, array{
         *     indexes: array<int, array<string, mixed>>
         * }>} $schema
         */
        $indexes = $schema['tables']['products']['indexes'];

        Assert::same($indexes[0]['name'], 'pk');
        Assert::true($indexes[0]['isPrimary'] ?? false);
    }

    #[Test]
    public function pkIndexHasOneEntryPerSeededRecord(): void
    {
        Fixture::boot();

        $entries = $this->readPkIndex('products');
        Assert::count($entries, 100);
    }

    #[Test]
    public function selectByIdUsesPkIndex(): void
    {
        $db = Fixture::db();

        $row = $db->table('products')->where('id', '=', 42)->selectOneByArray();
        Assert::notNull($row);
        Assert::same($row['id'], 42);
    }

    #[Test]
    public function insertAppendsEntryToPkIndex(): void
    {
        $db = Fixture::db();
        $beforeCount = \count($this->readPkIndex('categories'));

        $id = $db->table('categories')
            ->insertByArray(['name' => 'PkProbe', 'sort' => 9999]);

        $afterEntries = $this->readPkIndex('categories');
        Assert::count($afterEntries, $beforeCount + 1);

        $found = false;

        foreach ($afterEntries as $entry) {
            if ($entry['line'] === $beforeCount) {
                $found = true;
                break;
            }
        }

        Assert::true($found, 'no PK index entry for the newly inserted row');

        $db->table('categories')->where('id', '=', $id)->delete();
    }

    #[Test]
    public function deleteRebuildsPkIndex(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $id = $tbl->insertByArray(['name' => 'PkDelProbe', 'sort' => 8888]);
        $tbl->deleteById($id);

        $entries = $this->readPkIndex('categories');

        foreach ($entries as $entry) {
            Assert::int($entry['line'])->greaterThanOrEqual(0);
        }

        $row = $tbl->where('id', '=', $id)->selectOneByArray();
        Assert::null($row);
    }

    /**
     * @return array<int,array{key:string,line:int}>
     */
    private function readPkIndex(string $tableName): array
    {
        $manager = new IndexManager(new NdjsonStorage(Fixture::DB_PATH));
        $pk = IndexSchema::primaryFor();

        return $manager->readIndex($tableName, $pk);
    }
}
