<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\InMemoryCache;
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
 * count() with conditions: from the data cache when it holds the table's
 * current version, otherwise through an index that serves the conditions,
 * read the way a select reads it — so count() and a select with the same
 * conditions always agree.
 */
final class CountViaIndexTest
{
    private const string TABLE = 'points';

    private const string TWIN = 'points_twin';

    private string $root;

    private string $dbDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-count-via-index');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * Random conditions count what a select returns and what an unindexed
     * twin counts, before and after an appended tail.
     */
    #[Test]
    public function countsMatchSelectAndTwin(): void
    {
        $db = $this->database();
        mt_srand(20261005);
        $ids = range(1, 300);
        shuffle($ids);
        $rows = [];

        foreach ($ids as $id) {
            $rows[] = ['id' => $id] + self::row();
        }

        foreach ([self::TABLE, self::TWIN] as $table) {
            $db->importRecords($table, $rows);
        }

        $this->assertCountsMatch($db);

        for ($i = 0; $i < 20; $i++) {
            $row = self::row();

            foreach ([self::TABLE, self::TWIN] as $table) {
                $db->insert($table, $row);
            }
        }

        $this->assertCountsMatch($db);
    }

    /**
     * Without a cached copy the count goes through the index: a stored
     * value that no longer matches its index entry is found on the path.
     */
    #[Test]
    public function coldCountGoesThroughIndex(): void
    {
        $db = $this->seededFixed(null);
        $this->editDataLine('"n":3,', '"n":4,');

        try {
            $db->table(self::TABLE)->where('n', '=', 3)->count();
            Assert::fail('the count did not use the index');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'IndexRecordMismatch');
        }
    }

    /**
     * With the table's current version in the data cache the count is
     * taken from memory, without the index.
     */
    #[Test]
    public function warmCacheCountsFromMemory(): void
    {
        $db = $this->seededFixed(new InMemoryCache());
        Assert::same(\count($db->table(self::TABLE)->selectAllByArray()), 60);
        $this->editDataLine('"n":3,', '"n":4,');

        Assert::same($db->table(self::TABLE)->where('n', '=', 3)->count(), 10);
    }

    private function assertCountsMatch(JsonDataProvider $db): void
    {
        for ($i = 0; $i < 40; $i++) {
            $n = mt_rand(0, 9);
            $s = 's' . mt_rand(0, 4);
            $queries = [
                static fn (JsonTable $t): JsonTable => $t->where('n', '=', $n),
                static fn (JsonTable $t): JsonTable => $t
                    ->where('n', 'IN', [$n, ($n + 3) % 10, 42]),
                static fn (JsonTable $t): JsonTable => $t
                    ->where('n', 'BETWEEN', [$n, $n + 2]),
                static fn (JsonTable $t): JsonTable => $t->where('n', '>', $n),
                static fn (JsonTable $t): JsonTable => $t
                    ->where('n', '=', $n)->where('s', '<=', $s),
                static fn (JsonTable $t): JsonTable => $t
                    ->where('n', '=', $n)->where('s', 'LIKE', 's%'),
                static fn (JsonTable $t): JsonTable => $t
                    ->where('n', '<', $n)->where('f', '>', 0.5),
                static fn (JsonTable $t): JsonTable => $t->where('f', '=', 1.0),
            ];

            foreach ($queries as $q => $query) {
                $count = $query($db->table(self::TABLE))->count();
                $label = 'round ' . $i . ', query #' . $q;

                Assert::same(
                    $count,
                    \count($query($db->table(self::TABLE))->selectAllByArray()),
                    $label,
                );
                Assert::same(
                    $count,
                    $query($db->table(self::TWIN))->count(),
                    $label,
                );
            }
        }
    }

    private function database(
        InMemoryCache | null $cache = null,
    ): JsonDataProvider {
        $db = JsonDataProvider::createDatabase($this->dbDir, $cache);
        $columns = [
            'id' => ColumnTypes::INT,
            'n'  => ColumnTypes::INT_NULLABLE,
            's'  => ColumnTypes::STRING_NULLABLE,
            'f'  => ColumnTypes::FLOAT,
        ];
        $db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: $columns,
            indexes: [
                new IndexSchema('idx_n', [
                    new IndexFieldSchema('n', SortDirectionEnum::ASC),
                ]),
                new IndexSchema('idx_ns', [
                    new IndexFieldSchema('n', SortDirectionEnum::ASC),
                    new IndexFieldSchema('s', SortDirectionEnum::DESC),
                ]),
            ],
        ));
        $db->createTable(TableSchema::create(
            name: self::TWIN,
            columns: $columns,
        ));

        return $db;
    }

    /**
     * 60 rows: n = 1..6 ten times each.
     */
    private function seededFixed(
        InMemoryCache | null $cache,
    ): JsonDataProvider {
        $db = $this->database($cache);
        $rows = [];

        for ($id = 1; $id <= 60; $id++) {
            $rows[] = [
                'id' => $id,
                'n'  => intdiv($id - 1, 10) + 1,
                's'  => 's' . ($id % 5),
                'f'  => 0.5,
            ];
        }

        $db->importRecords(self::TABLE, $rows);

        return $db;
    }

    /**
     * @return array<string,null|scalar>
     */
    private static function row(): array
    {
        return [
            'n' => mt_rand(0, 9) === 0 ? null : mt_rand(0, 9),
            's' => mt_rand(0, 9) === 0 ? null : 's' . mt_rand(0, 4),
            'f' => [0.5, 1.0, 1.5][mt_rand(0, 2)],
        ];
    }

    /**
     * Replaces a value on the first data line holding it, in place: the
     * file keeps its length and inode, so the table stays trusted and its
     * cache version tag stays the same.
     */
    private function editDataLine(string $from, string $to): void
    {
        $path = $this->dbDir . '/' . self::TABLE . '/'
            . TableSchema::dataFileName(self::TABLE);
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
