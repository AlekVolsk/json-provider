<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
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

    #[Test]
    public function duplicateUniqueKeyInBatchIsRejectedTableUntouched(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'imp_codes',
            columns: ['code' => 'string'],
            uniqueConstraints: [new UniqueConstraint('uq_code', ['code'])],
        ));
        $this->db->insert('imp_codes', ['code' => 'kept']);

        try {
            $this->db->importRecords('imp_codes', [
                ['id' => 1, 'code' => 'X'],
                ['id' => 2, 'code' => 'X'],
            ]);
            Assert::fail('a duplicate unique key must abort the import');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'UniqueViolation');
        }

        Assert::same(
            $this->db->table('imp_codes')->selectColumn('code'),
            ['kept'],
        );
    }

    #[Test]
    public function cascadeFromOneParentLeavesAnotherParentsChildren(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'imp_parents',
            columns: ['code' => 'string'],
            uniqueConstraints: [new UniqueConstraint('uq_code', ['code'])],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'imp_children',
            columns: ['pcode' => 'string'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'imp_children',
            foreignKey: 'pcode',
            toTable: 'imp_parents',
            references: 'code',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        try {
            $this->db->importRecords('imp_parents', [
                ['id' => 1, 'code' => 'X'],
                ['id' => 2, 'code' => 'X'],
            ]);
            Assert::fail('two parents sharing a unique key must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'UniqueViolation');
        }

        $this->db->importRecords('imp_parents', [
            ['id' => 1, 'code' => 'X'],
            ['id' => 2, 'code' => 'Y'],
        ]);
        $this->db->importRecords('imp_children', [['id' => 1, 'pcode' => 'X']]);
        $this->db->table('imp_parents')->deleteById(2);

        Assert::same($this->db->table('imp_children')->count(), 1);
    }

    #[Test]
    public function autoIncrementIsRaisedButNeverLowered(): void
    {
        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->db->insert('imp_items', ['title' => 't' . $n, 'qty' => $n]);
        }

        $this->db->importRecords('imp_items', [
            ['id' => 1, 'title' => 'only', 'qty' => 1],
        ]);
        Assert::same(
            $this->db->insert('imp_items', ['title' => 'next', 'qty' => 0]),
            6,
        );

        $this->db->importRecords('imp_items', [
            ['id' => 40, 'title' => 'far', 'qty' => 1],
        ]);
        Assert::same(
            $this->db->insert('imp_items', ['title' => 'next', 'qty' => 0]),
            41,
        );
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
