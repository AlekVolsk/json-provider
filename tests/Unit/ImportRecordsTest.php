<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for importRecords: the bulk load path must leave the table in the
 * same state a sequence of insert() calls would — data replaced, indexes
 * rebuilt, meta counters (lineCount/byteSize) committed and the
 * auto-increment watermark moved past the imported ids — so nothing keyed on
 * those counters silently degrades afterwards.
 */
final class ImportRecordsTest
{
    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: 'imp_items',
            columns: [
                'id'    => 'int',
                'title' => 'string',
                'qty'   => 'int',
            ],
            indexes: [new IndexSchema(
                'idx_qty',
                [new IndexFieldSchema('qty', SortDirectionEnum::ASC)],
            )],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function importWritesRowsAndCommitsCounters(): void
    {
        $written = $this->db->importRecords('imp_items', [
            ['id' => 1, 'title' => 'a', 'qty' => 30],
            ['id' => 2, 'title' => 'b', 'qty' => 10],
            ['id' => 3, 'title' => 'c', 'qty' => 20],
        ]);

        Assert::same($written, 3);
        Assert::same($this->db->table('imp_items')->count(), 3);

        $entry = $this->metaEntry('imp_items');
        Assert::same($entry['lineCount'], 3);
        Assert::same($entry['lastInsertedId'], 3);

        $size = filesize($this->dbDir . '/imp_items/imp_items.ndjson');
        Assert::same($entry['byteSize'], $size);
    }

    /**
     * The committed byteSize is what lets an unconditional count() answer
     * from meta instead of reading the table — a stale counter would either
     * lie or silently disable the shortcut.
     */
    #[Test]
    public function countAfterImportMatchesTheStoredRows(): void
    {
        $this->db->importRecords('imp_items', [
            ['id' => 1, 'title' => 'a', 'qty' => 1],
            ['id' => 2, 'title' => 'b', 'qty' => 2],
        ]);

        Assert::same($this->db->table('imp_items')->count(), 2);
        Assert::same(
            \count($this->db->readAll('imp_items')),
            $this->db->table('imp_items')->count(),
        );
    }

    #[Test]
    public function importRebuildsIndexes(): void
    {
        $this->db->importRecords('imp_items', [
            ['id' => 1, 'title' => 'a', 'qty' => 30],
            ['id' => 2, 'title' => 'b', 'qty' => 10],
        ]);

        $rows = $this->db->table('imp_items')
            ->orderBy('qty', 'asc')
            ->limit(2)
            ->selectAllByArray();

        Assert::same(array_column($rows, 'id'), [2, 1]);

        $index = file_get_contents(
            $this->dbDir . '/imp_items/idx_qty.index.ndjson',
        );
        Assert::same(substr_count((string)$index, "\n"), 2);
    }

    #[Test]
    public function importReplacesPreviousContent(): void
    {
        $this->db->importRecords('imp_items', [
            ['id' => 1, 'title' => 'old', 'qty' => 1],
            ['id' => 2, 'title' => 'gone', 'qty' => 2],
        ]);
        $this->db->importRecords('imp_items', [
            ['id' => 5, 'title' => 'new', 'qty' => 5],
        ]);

        Assert::same($this->db->table('imp_items')->count(), 1);

        $row = $this->db->table('imp_items')
            ->where('id', '=', 5)
            ->selectOneByArray();
        Assert::same($row['title'] ?? null, 'new');
        Assert::same($this->metaEntry('imp_items')['lastInsertedId'], 5);
    }

    /**
     * The watermark comes from the largest imported id, not from the row
     * count, so a sparse import still leaves the next insert collision-free.
     */
    #[Test]
    public function insertAfterImportContinuesPastTheLargestId(): void
    {
        $this->db->importRecords('imp_items', [
            ['id' => 4, 'title' => 'a', 'qty' => 1],
            ['id' => 9, 'title' => 'b', 'qty' => 2],
            ['id' => 7, 'title' => 'c', 'qty' => 3],
        ]);

        $id = $this->db->insert('imp_items', ['title' => 'd', 'qty' => 4]);

        Assert::same($id, 10);
        Assert::same($this->db->table('imp_items')->count(), 4);
        Assert::false(
            $this->db->validateTable('imp_items')->hasErrors(),
        );
    }

    #[Test]
    public function importOfAnEmptySetEmptiesTheTable(): void
    {
        $this->db->importRecords('imp_items', [
            ['id' => 1, 'title' => 'a', 'qty' => 1],
        ]);
        $written = $this->db->importRecords('imp_items', []);

        Assert::same($written, 0);
        Assert::same($this->db->table('imp_items')->count(), 0);
        Assert::same($this->metaEntry('imp_items')['lineCount'], 0);
    }

    #[Test]
    public function importValidatesRecordsAgainstTheSchema(): void
    {
        try {
            $this->db->importRecords('imp_items', [
                ['id' => 1, 'title' => 'a', 'qty' => 'not-an-int'],
            ]);
            Assert::fail('expected a validation failure');
        } catch (JsonProviderException) {
            Assert::same($this->db->table('imp_items')->count(), 0);
        }
    }

    #[Test]
    public function importRejectsAnUnknownTable(): void
    {
        try {
            $this->db->importRecords('nope', [['id' => 1]]);
            Assert::fail('expected a missing-table failure');
        } catch (JsonProviderException $e) {
            Assert::true($e->getMessage() !== '');
        }
    }

    /**
     * @return array<mixed>
     */
    private function metaEntry(string $table): array
    {
        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        \assert(\is_array($meta) && \is_array($meta[$table]));

        return $meta[$table];
    }

    private function removeDir(string $path): void
    {
        TempDir::remove($path);
    }

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-import-tests');
    }
}
