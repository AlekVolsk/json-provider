<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\JsonTable;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\EngineAccess;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Selects on a foreign-key column served by the engine's service index.
 *
 * A query answered through an index returns the rows a full scan of an
 * unindexed twin returns, in the same order: file order without orderBy,
 * the requested order with it. The rows here are stored in an order that
 * follows neither the id nor the indexed column, so an answer in index
 * order would show.
 */
final class ServiceIndexSelectTest
{
    private const string OWNERS = 'owners';

    private const string ITEMS = 'items';

    private const string TWIN = 'items_twin';

    private string $root;

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-service-index-select');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
        $this->db->createTable(TableSchema::create(
            name: self::OWNERS,
            columns: ['id' => ColumnTypes::INT, 'name' => ColumnTypes::STRING],
        ));
        $owners = [];

        for ($id = 1; $id <= 20; $id++) {
            $owners[] = ['id' => $id, 'name' => 'owner' . $id];
        }

        $this->db->importRecords(self::OWNERS, $owners);
        $columns = [
            'id'      => ColumnTypes::INT,
            'ownerId' => ColumnTypes::INT,
            'val'     => ColumnTypes::INT,
        ];

        foreach ([self::ITEMS, self::TWIN] as $table) {
            $this->db->createTable(TableSchema::create(
                name: $table,
                columns: $columns,
            ));
        }

        $this->db->addRelation(new RelationSchema(
            fromTable: self::ITEMS,
            foreignKey: 'ownerId',
            toTable: self::OWNERS,
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        mt_srand(20261005);
        $ids = range(1, 300);
        shuffle($ids);
        $rows = [];

        foreach ($ids as $id) {
            $rows[] = [
                'id'      => $id,
                'ownerId' => mt_rand(1, 20),
                'val'     => mt_rand(0, 999),
            ];
        }

        foreach ([self::ITEMS, self::TWIN] as $table) {
            $this->db->importRecords($table, $rows);
        }

        Assert::true(
            $this->serviceIndexes() !== [],
            'the relation has a service index',
        );
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * Without orderBy the rows come in file order, with and without
     * pagination, for every operator an index can serve.
     */
    #[Test]
    public function unorderedSelectsKeepFileOrder(): void
    {
        $queries = [
            static fn (JsonTable $t): JsonTable => $t->where('ownerId', '=', 7),
            static fn (JsonTable $t): JsonTable => $t
                ->where('ownerId', 'IN', [3, 9, 14]),
            static fn (JsonTable $t): JsonTable => $t
                ->where('ownerId', 'BETWEEN', [5, 8]),
            static fn (JsonTable $t): JsonTable => $t
                ->where('ownerId', '>', 15),
            static fn (JsonTable $t): JsonTable => $t
                ->where('ownerId', '<=', 4)->limit(5)->offset(3),
            static fn (JsonTable $t): JsonTable => $t
                ->where('ownerId', '=', 11)->where('val', '>', 300),
        ];

        foreach ($queries as $i => $query) {
            Assert::same(
                $this->ids($query, self::ITEMS),
                $this->ids($query, self::TWIN),
                'query #' . $i,
            );
        }
    }

    /**
     * With orderBy on the column, a page comes in the requested order.
     */
    #[Test]
    public function orderedPagesMatchTwin(): void
    {
        foreach (['asc', 'desc'] as $direction) {
            $query = static fn (JsonTable $t): JsonTable => $t
                ->orderBy('ownerId', $direction)->limit(10)->offset(20);

            Assert::same(
                $this->ids($query, self::ITEMS),
                $this->ids($query, self::TWIN),
                $direction,
            );
        }
    }

    /**
     * The select goes through the service index: a stored value that no
     * longer matches the entry pointing at it is found on the lookup path.
     */
    #[Test]
    public function serviceIndexServesSelect(): void
    {
        $this->editFirstDataLine('"ownerId":7,', '"ownerId":8,');

        try {
            $this->db->table(self::ITEMS)->where('ownerId', '=', 7)
                ->selectAllByArray();
            Assert::fail('the select did not use the service index');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'IndexRecordMismatch');
        }
    }

    /**
     * Dropping the relation drops its service index, and selects on the
     * column fall back to a full scan with the same answers.
     */
    #[Test]
    public function droppedRelationLeavesFullScan(): void
    {
        $this->db->dropRelation(self::ITEMS, 'ownerId', self::OWNERS);

        Assert::same($this->serviceIndexes(), []);

        $query = static fn (JsonTable $t): JsonTable => $t
            ->where('ownerId', 'IN', [2, 12]);
        Assert::same(
            $this->ids($query, self::ITEMS),
            $this->ids($query, self::TWIN),
        );
    }

    /**
     * @param \Closure(JsonTable): JsonTable $query
     *
     * @return array<int,mixed>
     */
    private function ids(\Closure $query, string $table): array
    {
        return array_column(
            $query($this->db->table($table))->selectAllByArray(),
            'id',
        );
    }

    /**
     * @return array<int,string>
     */
    private function serviceIndexes(): array
    {
        $registry = EngineAccess::context($this->db)->schema;
        $registry->reload();
        $names = [];

        foreach ($registry->getTable(self::ITEMS)->indexes as $index) {
            if ($index->isService) {
                $names[] = $index->name;
            }
        }

        return $names;
    }

    /**
     * Replaces a value on the first data line holding it, in place: the
     * file keeps its length and inode, so the table stays trusted.
     */
    private function editFirstDataLine(string $from, string $to): void
    {
        $path = $this->dbDir . '/' . self::ITEMS . '/'
            . TableSchema::dataFileName(self::ITEMS);
        $lines = explode("\n", rtrim((string)file_get_contents($path)));

        foreach ($lines as $i => $line) {
            if (str_contains($line, $from)) {
                $lines[$i] = str_replace($from, $to, $line);
                file_put_contents($path, implode("\n", $lines) . "\n");

                return;
            }
        }

        Assert::fail('no data line holds ' . $from);
    }
}
