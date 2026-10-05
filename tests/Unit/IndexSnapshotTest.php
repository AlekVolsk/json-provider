<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Engine\IndexReader;
use AV\JsonProvider\Index\IndexSnapshot;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\EngineAccess;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * An index read takes a snapshot under the table lock — the index and the
 * data file open as they are — and reads it after the lock is released.
 * A writer that replaces the table in between does not reach the snapshot:
 * the read answers from the version it opened, whole.
 */
final class IndexSnapshotTest
{
    private const string TABLE = 'events';

    private string $root;

    private string $dbDir;

    private JsonDataProvider $db;

    private IndexSchema $index;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-index-snapshot');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
        $this->index = new IndexSchema('idx_grp', [
            new IndexFieldSchema('grp', SortDirectionEnum::ASC),
        ]);
        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: [
                'id'  => ColumnTypes::INT,
                'grp' => ColumnTypes::STRING,
                'seq' => ColumnTypes::INT,
            ],
            indexes: [$this->index],
        ));
        $this->db->importRecords(self::TABLE, self::version(100, 20));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * A filtered select, a select in index order and a count() over a
     * snapshot answer from the version opened, after the table was
     * replaced by one with the rows moved.
     */
    #[Test]
    public function snapshotOutlivesReplacement(): void
    {
        $where = [new FilterCondition('grp', FilterOperatorEnum::EQ, 'a')];
        $order = [new OrderBy('grp', SortDirectionEnum::ASC)];
        $filtered = $this->snapshot([]);
        $ordered = $this->snapshot($order);
        $counted = $this->snapshot([]);

        $this->replaceInChild(self::version(101, 25));

        $reads = [[$filtered, []], [$ordered, $order]];

        foreach ($reads as [$snapshot, $ordering]) {
            $rows = $this->call(
                'selectViaIndexTrusted',
                $snapshot,
                $this->index,
                $this->schema(),
                $where,
                $ordering,
            );
            Assert::true(\is_array($rows));
            Assert::same(array_column($rows, 'seq'), array_fill(0, 10, 100));
        }

        $matching = $this->call(
            'matchingRecords',
            $counted,
            $this->schema(),
            $this->index,
            $where,
            $this->call(
                'indexLineNumbers',
                $this->schema(),
                $this->index,
                $where,
                $counted->sorted,
                $counted->entries,
            ),
        );
        Assert::true($matching instanceof \Generator);
        Assert::same(iterator_count($matching), 10);

        Assert::same(
            array_column(
                $this->db->table(self::TABLE)->where('grp', '=', 'a')
                    ->selectAllByArray(),
                'seq',
            ),
            array_fill(0, 10, 101),
        );
    }

    /**
     * @param array<int,OrderBy> $ordering
     */
    private function snapshot(array $ordering): IndexSnapshot
    {
        $snapshot = $this->call(
            'openIndexSnapshot',
            $this->index,
            $this->schema(),
            $ordering,
        );
        Assert::true($snapshot instanceof IndexSnapshot);

        return $snapshot;
    }

    private function schema(): TableSchema
    {
        return EngineAccess::context($this->db)->schema->getTable(self::TABLE);
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $reader = EngineAccess::part($this->db, 'indexReader');
        Assert::true($reader instanceof IndexReader);

        return EngineAccess::call($reader, $method, ...$args);
    }

    /**
     * Replaces the table from another process, as a concurrent writer.
     *
     * @param array<int,array<string,int|string>> $rows
     */
    private function replaceInChild(array $rows): void
    {
        $code = <<<'PHP'
            require $argv[1];
            \AV\JsonProvider\JsonDataProvider::getInstance($argv[2])
                ->importRecords($argv[3], json_decode($argv[4], true));
            PHP;
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                $code,
                '--',
                \dirname(__DIR__, 2) . '/vendor/autoload.php',
                $this->dbDir,
                self::TABLE,
                json_encode($rows, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        Assert::true(\is_resource($process));
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        Assert::same(proc_close($process), 0, $stderr);
    }

    /**
     * Ten rows of group "a" with one seq, after $before rows of group "b".
     *
     * @return array<int,array<string,int|string>>
     */
    private static function version(int $seq, int $before): array
    {
        $rows = [];

        for ($i = 0; $i < $before; $i++) {
            $rows[] = ['id' => \count($rows) + 1, 'grp' => 'b', 'seq' => $i];
        }

        for ($i = 0; $i < 10; $i++) {
            $rows[] = ['id' => \count($rows) + 1, 'grp' => 'a', 'seq' => $seq];
        }

        return $rows;
    }
}
