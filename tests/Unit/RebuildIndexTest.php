<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Tests\Support\Fixture;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

/**
 * Tests for JsonDataProvider::rebuildIndex / rebuildAllIndexes and
 * the JsonTable wrappers.
 *
 * Contract:
 *  - rebuildIndex restores a named index file to its canonical state derived
 *    from current table data, even if the file was tampered with;
 *  - rebuildAllIndexes does the same for every index, including PK;
 *  - unknown index name — JsonProviderException::indexNotFound;
 *  - JsonTable::rebuildIndex / rebuildAllIndexes are meta-operations:
 *    they do not consume or reset accumulated query state.
 */
final class RebuildIndexTest
{
    #[Test]
    public function rebuildIndexRestoresPkAfterCorruption(): void
    {
        $db = Fixture::db();

        $expected = $this->readIndexEntries('products', 'pk');
        Assert::count($expected, 100);

        file_put_contents(
            Fixture::dbPath() . '/products/pk.index.ndjson',
            "GARBAGE\n",
        );

        $db->table('products')->rebuildIndex('pk');

        $rebuilt = $this->readIndexEntries('products', 'pk');
        Assert::same($rebuilt, $expected);
    }

    #[Test]
    public function rebuildIndexRestoresUserIndexAfterCorruption(): void
    {
        $db = Fixture::db();

        $expected = $this->readIndexEntries('products', 'idx_category');
        Assert::count($expected, 100);

        file_put_contents(
            Fixture::dbPath() . '/products/idx_category.index.ndjson',
            '',
        );

        $db->table('products')->rebuildIndex('idx_category');

        $rebuilt = $this->readIndexEntries('products', 'idx_category');
        Assert::same($rebuilt, $expected);
    }

    #[Test]
    public function rebuildIndexThrowsOnUnknownIndexName(): void
    {
        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('no index named "ghost"');

        Fixture::db()->table('products')->rebuildIndex('ghost');
    }

    #[Test]
    public function rebuildAllIndexesRestoresEveryFile(): void
    {
        $db = Fixture::db();

        $names = ['pk', 'idx_category', 'idx_price_desc'];
        $expected = [];

        foreach ($names as $name) {
            $expected[$name] = $this->readIndexEntries('products', $name);
            file_put_contents(
                Fixture::dbPath() . '/products/' . $name . '.index.ndjson',
                "CORRUPTED\n",
            );
        }

        $db->table('products')->rebuildAllIndexes();

        foreach ($names as $name) {
            $rebuilt = $this->readIndexEntries('products', $name);
            Assert::same($rebuilt, $expected[$name], "index: {$name}");
        }
    }

    #[Test]
    public function rebuildIndexDoesNotResetAccumulatedQueryState(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('products');

        $tbl->where('category_id', '=', 3);

        $tbl->rebuildIndex('pk');

        Assert::same($tbl->count(), 10);
    }

    #[Test]
    public function rebuildAllIndexesDoesNotResetAccumulatedQueryState(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('products');

        $tbl->where('category_id', '=', 5);

        $tbl->rebuildAllIndexes();

        Assert::same($tbl->count(), 10);
    }

    #[Test]
    public function rebuildIndexProducesSortedKeys(): void
    {
        $db = Fixture::db();

        $db->table('products')->rebuildIndex('idx_category');

        $entries = $this->readIndexEntries('products', 'idx_category');

        $keys = array_column($entries, 'key');
        $sortedKeys = $keys;
        sort($sortedKeys);

        Assert::same($keys, $sortedKeys);
    }

    /**
     * Reads an index file via IndexManager (sorted by key for stable
     * comparison).
     *
     * @return array<int,array{key:string,line:int}>
     */
    private function readIndexEntries(
        string $tableName,
        string $indexName,
    ): array {
        $manager = new IndexManager(new NdjsonStorage(Fixture::dbPath()));

        $idx = new IndexSchema(
            name: $indexName,
            fields: [
                new IndexFieldSchema('category_id', SortDirectionEnum::ASC),
            ],
        );

        return $manager->readIndex($tableName, $idx);
    }
}
