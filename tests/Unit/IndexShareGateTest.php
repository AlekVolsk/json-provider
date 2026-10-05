<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\JsonTable;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * An index lookup that finds more than 80% of the table's lines is dropped
 * for a full scan — reading that many lines through the index costs more
 * than reading them all. Up to that share the index serves the query.
 *
 * Which path answered is seen through a damaged row: its stored value no
 * longer matches the index entry pointing at it, which the index path
 * reports and a full scan does not see.
 */
final class IndexShareGateTest
{
    private const string TABLE = 'items';

    private string $root;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-index-share-gate');
        $dbDir = $this->root . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($dbDir);
        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: ['id' => ColumnTypes::INT, 'n' => ColumnTypes::INT],
            indexes: [new IndexSchema('idx_n', [
                new IndexFieldSchema('n', SortDirectionEnum::ASC),
            ])],
        ));
        $rows = [];

        for ($id = 1; $id <= 100; $id++) {
            $rows[] = ['id' => $id, 'n' => $id % 10];
        }

        $this->db->importRecords(self::TABLE, $rows);

        $path = $dbDir . '/' . self::TABLE . '/'
            . TableSchema::dataFileName(self::TABLE);
        $data = (string)file_get_contents($path);
        $at = strpos($data, '"n":3}');
        Assert::true($at !== false);
        file_put_contents($path, substr_replace($data, '"n":4}', $at, 6));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * Up to 80% of the lines, select and count() go through the index and
     * meet the damaged row. A numeric bound takes its equal values in, so
     * `<= 7` is the 80% case here, while `< 8` would read 90%.
     */
    #[Test]
    public function indexServesUpToTheShare(): void
    {
        $conditions = [['<', 5], ['<=', 7], ['IN', range(0, 7)]];
        $runs = [
            static fn (JsonTable $t): mixed => $t->selectAllByArray(),
            static fn (JsonTable $t): mixed => $t->count(),
        ];

        foreach ($conditions as $c) {
            $query = fn (): JsonTable => $this->db->table(self::TABLE)
                ->where('n', $c[0], $c[1]);

            foreach ($runs as $run) {
                try {
                    $run($query());
                    Assert::fail('the index did not serve ' . json_encode($c));
                } catch (JsonProviderException $e) {
                    Assert::same($e->getErrorKey(), 'IndexRecordMismatch');
                }
            }
        }
    }

    /**
     * Past 80% of the lines, select and count() read the table whole and
     * answer from the data as stored.
     */
    #[Test]
    public function fullScanPastTheShare(): void
    {
        foreach ([['<', 9], ['IN', [0, 1, 2, 3, 4, 5, 6, 7, 8]]] as $c) {
            $rows = $this->db->table(self::TABLE)
                ->where('n', $c[0], $c[1])->selectAllByArray();
            $ids = array_column($rows, 'id');
            $expected = array_values(array_filter(
                range(1, 100),
                static fn (int $id): bool => $id % 10 !== 9,
            ));

            Assert::same($ids, $expected);
            Assert::same(
                $this->db->table(self::TABLE)->where('n', $c[0], $c[1])
                    ->count(),
                90,
            );
        }
    }
}
