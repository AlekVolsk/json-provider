<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Index\IndexEntryList;
use AV\JsonProvider\Index\IndexKey;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\JsonTable;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Lookups by several leading fields of a composite index: `=` on the
 * first fields and an optional range on the field right after them,
 * searched as one key prefix. The planner picks the index whose leading
 * fields the conditions narrow the most when that is two fields or more;
 * a query narrowing one field keeps the index it had.
 */
final class CompositePrefixLookupTest
{
    private const string TABLE = 'cells';

    private const string TWIN = 'cells_twin';

    private string $root;

    private string $dbDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-composite-prefix');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * Random prefix queries — on an int, a DESC float and a string field,
     * with nulls — answer exactly what a full scan of an unindexed twin
     * answers, in the same order, over a sorted index and with a tail.
     */
    #[Test]
    public function prefixLookupsMatchFullScanOfTwin(): void
    {
        $db = $this->database([self::index('idx_abc', [
            'a' => SortDirectionEnum::ASC,
            'b' => SortDirectionEnum::DESC,
            'c' => SortDirectionEnum::ASC,
        ])]);
        mt_srand(20261005);
        $this->seed($db, 400);
        $this->assertQueriesMatchTwin($db);

        for ($i = 0; $i < 30; $i++) {
            $row = self::row();

            foreach ([self::TABLE, self::TWIN] as $table) {
                $db->insert($table, $row);
            }
        }

        $this->assertQueriesMatchTwin($db);
    }

    /**
     * The prefix lookup returns exactly the entries with the prefix whose
     * next field is in range — a numeric bound keeps its equal values, as
     * every numeric bound does — never entries outside the prefix.
     */
    #[Test]
    public function prefixLookupReturnsOnlyItsRun(): void
    {
        $index = self::index('idx_abc', [
            'a' => SortDirectionEnum::ASC,
            'b' => SortDirectionEnum::DESC,
            'c' => SortDirectionEnum::ASC,
        ]);
        mt_srand(20261006);
        $records = [];

        for ($line = 0; $line < 500; $line++) {
            $records[$line] = self::row();
        }

        $entries = [];

        foreach ($records as $line => $record) {
            $entries[] = [
                'key'  => IndexKey::build($record, $index),
                'line' => $line,
            ];
        }

        usort(
            $entries,
            static fn (array $x, array $y): int => strcmp($x['key'], $y['key']),
        );
        $list = new IndexEntryList($entries);
        mkdir($this->dbDir, 0755, true);
        $manager = new IndexManager(new NdjsonStorage($this->dbDir));

        foreach (self::lookups() as $i => [$values, $range, $expected]) {
            $lines = $manager->searchPrefixIn($list, $index, $values, $range);
            sort($lines);
            $want = array_keys(array_filter($records, $expected));

            Assert::same($lines, $want, 'lookup #' . $i);
        }
    }

    /**
     * Of two indexes on the leading field, the one the conditions narrow
     * further serves the query: damage visible only through its key is
     * found on the lookup path.
     */
    #[Test]
    public function widestIndexIsChosen(): void
    {
        $db = $this->database([
            self::index('idx_a', ['a' => SortDirectionEnum::ASC]),
            self::index('idx_ab', [
                'a' => SortDirectionEnum::ASC,
                'b' => SortDirectionEnum::DESC,
            ]),
        ]);
        $this->seedFixed($db);
        $this->editDataLine('"a":3,"b":1.5,', '"a":3,"b":2.5,');

        $this->assertUnreliable(
            static fn (): mixed => $db->table(self::TABLE)
                ->where('a', '=', 3)->where('b', '=', 1.5)
                ->selectAllByArray(),
        );
        Assert::same(
            \count($db->table(self::TABLE)->where('a', '=', 3)
                ->selectAllByArray()),
            10,
        );
    }

    /**
     * The lookup reads only the run of its prefix: a damaged row with the
     * same first field but another second one is off its path.
     */
    #[Test]
    public function prefixLookupReadsOnlyItsRun(): void
    {
        $db = $this->database([self::index('idx_ab', [
            'a' => SortDirectionEnum::ASC,
            'b' => SortDirectionEnum::DESC,
        ])]);
        $this->seedFixed($db);
        $this->editDataLine('"a":3,"b":0.5,', '"a":3,"b":2.5,');

        Assert::same(
            array_column(
                $db->table(self::TABLE)->where('a', '=', 3)
                    ->where('b', '=', 1.5)->selectAllByArray(),
                'id',
            ),
            [22, 26, 30],
        );
    }

    /**
     * A query that narrows one field keeps the first index whose first
     * field it filters, as before — here the composite one, declared
     * first.
     */
    #[Test]
    public function singleFieldChoiceIsUnchanged(): void
    {
        $db = $this->database([
            self::index('idx_ab', [
                'a' => SortDirectionEnum::ASC,
                'b' => SortDirectionEnum::DESC,
            ]),
            self::index('idx_a', ['a' => SortDirectionEnum::ASC]),
        ]);
        $this->seedFixed($db);
        $this->editDataLine('"a":3,"b":1.5,', '"a":3,"b":2.5,');

        $this->assertUnreliable(
            static fn (): mixed => $db->table(self::TABLE)
                ->where('a', '=', 3)->selectAllByArray(),
        );
    }

    /**
     * @return array<int,array{
     *     array<int,null|scalar>,
     *     null|FilterCondition,
     *     \Closure(array<string,null|scalar>): bool,
     * }>
     */
    private static function lookups(): array
    {
        $is = static fn (mixed $x, mixed $y): bool => $x !== null
            && $y !== null
            && $x === $y;

        return [
            [[3], null, static fn (array $r): bool => $is($r['a'], 3)],
            [[3, 1.5], null, static fn (array $r): bool => $is($r['a'], 3)
                && $is($r['b'], 1.5)],
            [[null], null, static fn (array $r): bool => $r['a'] === null],
            [
                [3],
                new FilterCondition('b', FilterOperatorEnum::GT, 1.5),
                static fn (array $r): bool => $is($r['a'], 3)
                    && $r['b'] !== null && $r['b'] >= 1.5,
            ],
            [
                [3],
                new FilterCondition('b', FilterOperatorEnum::LTE, 2.0),
                static fn (array $r): bool => $is($r['a'], 3)
                    && ($r['b'] === null || $r['b'] <= 2.0),
            ],
            [
                [4],
                new FilterCondition(
                    'b',
                    FilterOperatorEnum::BETWEEN,
                    [0.5, 2.0],
                ),
                static fn (array $r): bool => $is($r['a'], 4)
                    && $r['b'] !== null && $r['b'] >= 0.5 && $r['b'] <= 2.0,
            ],
            [
                [2, 1.0],
                new FilterCondition('c', FilterOperatorEnum::GTE, 'k3'),
                static fn (array $r): bool => $is($r['a'], 2)
                    && $is($r['b'], 1.0)
                    && \is_string($r['c']) && strcmp($r['c'], 'k3') >= 0,
            ],
            [
                [2, 1.0],
                new FilterCondition('c', FilterOperatorEnum::LT, 'k3'),
                static fn (array $r): bool => $is($r['a'], 2)
                    && $is($r['b'], 1.0)
                    && ($r['c'] === null
                        || (\is_string($r['c'])
                            && strcmp($r['c'], 'k3') < 0)),
            ],
        ];
    }

    private function assertQueriesMatchTwin(JsonDataProvider $db): void
    {
        for ($i = 0; $i < 60; $i++) {
            $a = mt_rand(0, 9) === 0 ? null : mt_rand(0, 5);
            $b = [0.5, 1.0, 1.5, 2.0][mt_rand(0, 3)];
            $c = 'k' . mt_rand(0, 6);
            $queries = [
                static fn (JsonTable $t): JsonTable => $t->where('a', '=', $a)
                    ->where('b', '=', $b),
                static fn (JsonTable $t): JsonTable => $t->where('a', '=', $a)
                    ->where('b', '>', $b),
                static fn (JsonTable $t): JsonTable => $t->where('a', '=', $a)
                    ->where('b', '<=', $b)->limit(4)->offset(1),
                static fn (JsonTable $t): JsonTable => $t->where('a', '=', $a)
                    ->where('b', 'BETWEEN', [$b, 2.0]),
                static fn (JsonTable $t): JsonTable => $t->where('c', '<', $c)
                    ->where('a', '=', $a)->where('b', '=', $b),
                static fn (JsonTable $t): JsonTable => $t->where('a', '=', $a)
                    ->where('b', '=', $b)->where('c', '=', $c),
                static fn (JsonTable $t): JsonTable => $t->where('a', '=', $a)
                    ->where('c', '=', $c),
            ];

            foreach ($queries as $q => $query) {
                Assert::same(
                    array_column(
                        $query($db->table(self::TABLE))->selectAllByArray(),
                        'id',
                    ),
                    array_column(
                        $query($db->table(self::TWIN))->selectAllByArray(),
                        'id',
                    ),
                    'round ' . $i . ', query #' . $q,
                );
            }
        }
    }

    /**
     * @param array<int,IndexSchema> $indexes
     */
    private function database(array $indexes): JsonDataProvider
    {
        $db = JsonDataProvider::createDatabase($this->dbDir);
        $columns = [
            'id' => ColumnTypes::INT,
            'a'  => ColumnTypes::INT_NULLABLE,
            'b'  => ColumnTypes::FLOAT_NULLABLE,
            'c'  => ColumnTypes::STRING_NULLABLE,
        ];
        $db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: $columns,
            indexes: $indexes,
        ));
        $db->createTable(TableSchema::create(
            name: self::TWIN,
            columns: $columns,
        ));

        return $db;
    }

    /**
     * Rows stored in an order that follows neither the id nor any key.
     */
    private function seed(JsonDataProvider $db, int $count): void
    {
        $ids = range(1, $count);
        shuffle($ids);
        $rows = [];

        foreach ($ids as $id) {
            $rows[] = ['id' => $id] + self::row();
        }

        foreach ([self::TABLE, self::TWIN] as $table) {
            $db->importRecords($table, $rows);
        }
    }

    /**
     * 60 rows: a = 1..6 ten times each, b cycling 0.5..2.0.
     */
    private function seedFixed(JsonDataProvider $db): void
    {
        $rows = [];

        for ($id = 1; $id <= 60; $id++) {
            $rows[] = [
                'id' => $id,
                'a'  => intdiv($id - 1, 10) + 1,
                'b'  => [0.5, 1.0, 1.5, 2.0][$id % 4],
                'c'  => 'k' . $id,
            ];
        }

        $db->importRecords(self::TABLE, $rows);
    }

    /**
     * @return array<string,null|scalar>
     */
    private static function row(): array
    {
        return [
            'a' => mt_rand(0, 9) === 0 ? null : mt_rand(0, 5),
            'b' => mt_rand(0, 9) === 0
                ? null
                : [0.5, 1.0, 1.5, 2.0][mt_rand(0, 3)],
            'c' => mt_rand(0, 9) === 0 ? null : 'k' . mt_rand(0, 6),
        ];
    }

    /**
     * @param \Closure(): mixed $query
     */
    private function assertUnreliable(\Closure $query): void
    {
        try {
            $query();
            Assert::fail('the query did not use the expected index');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'IndexRecordMismatch');
        }
    }

    /**
     * Replaces a value on the first data line holding it, in place: the
     * file keeps its length and inode, so the table stays trusted.
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

    /**
     * @param array<string,SortDirectionEnum> $fields
     */
    private static function index(string $name, array $fields): IndexSchema
    {
        $parts = [];

        foreach ($fields as $field => $direction) {
            $parts[] = new IndexFieldSchema($field, $direction);
        }

        return new IndexSchema($name, $parts);
    }
}
