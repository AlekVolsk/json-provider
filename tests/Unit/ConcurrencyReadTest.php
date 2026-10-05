<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Tests\Support\ChildPhp;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the two-tier reader model: lock-free full scans always see one
 * complete file; index-driven selects hold the table SH lock across the
 * index+data I/O, so concurrent rewrites can never produce a torn
 * index/data pair.
 */
final class ConcurrencyReadTest
{
    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        TempDir::remove(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: 'events',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'grp' => 'string', 'seq' => 'int'],
            indexes: [
                new IndexSchema(
                    name: 'idx_grp',
                    fields: [new IndexFieldSchema(
                        'grp',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));

        for ($i = 1; $i <= 10; $i++) {
            $this->db->insert('events', ['grp' => 'a', 'seq' => $i]);
        }

        for ($i = 1; $i <= 10; $i++) {
            $this->db->insert('events', ['grp' => 'b', 'seq' => $i]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove(self::dbPathRoot());
    }

    #[Test]
    public function fullScanIsLockFreeEvenUnderForeignTableEx(): void
    {
        $holder = $this->spawnFlockHolder(
            $this->dbDir . '/.locks/table.events.lock',
        );

        try {
            $start = microtime(true);
            $rows = $this->db->table('events')->selectAllByArray();
            $elapsed = microtime(true) - $start;

            Assert::count($rows, 20);
            Assert::float($elapsed)->lessThan(2.0);
        } finally {
            $this->releaseHolder($holder);
        }
    }

    #[Test]
    public function indexedSelectsStayCoherentDuringRewriteChurn(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            echo "ready\n";
            fflush(STDOUT);
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                $db->table('events')->where('grp', '=', 'b')->delete();
                for ($i = 1; $i <= 10; $i++) {
                    $db->insert('events', ['grp' => 'b', 'seq' => $i]);
                }
            }
            echo "done\n";
            PHP;

        $child = ChildPhp::spawn($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
        ]);

        $ready = fgets($child['pipes'][1]);
        Assert::string((string)$ready)->contains('ready');

        try {
            $reads = 0;
            $deadline = microtime(true) + 2.5;

            while (microtime(true) < $deadline) {
                $rows = $this->db->table('events')
                    ->where('grp', '=', 'a')
                    ->orderBy('grp', 'asc')
                    ->selectAllByArray();

                Assert::count($rows, 10);

                $seqs = [];

                foreach ($rows as $row) {
                    Assert::same($row['grp'], 'a');
                    $seqs[] = $row['seq'];
                }

                sort($seqs);
                Assert::same($seqs, range(1, 10));
                $reads++;
            }

            Assert::int($reads)->greaterThan(0);
        } finally {
            $stdout = ChildPhp::drain($child);
        }

        Assert::string($stdout)->contains('done');
    }

    /**
     * An index read takes the index and the data file of one version and
     * reads them after releasing the table lock. While a writer replaces
     * the table with versions that move the rows the read finds — a
     * different number of other rows before them each time — every select
     * and count() sees one version: ten rows of one seq, and never an
     * index entry pointing into another version.
     */
    #[Test]
    public function indexedReadsKeepOneSnapshotDuringRewrites(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            $version = static function (int $v): array {
                $rows = [];
                for ($i = 0; $i < $v % 7; $i++) {
                    $rows[] = ['id' => \count($rows) + 1, 'grp' => 'b',
                        'seq' => $i];
                }
                for ($i = 0; $i < 10; $i++) {
                    $rows[] = ['id' => \count($rows) + 1, 'grp' => 'a',
                        'seq' => $v];
                }
                return $rows;
            };
            $db->importRecords('events', $version(100));
            echo "ready\n";
            fflush(STDOUT);
            $deadline = microtime(true) + 3.0;
            for ($v = 101; microtime(true) < $deadline; $v++) {
                $db->importRecords('events', $version($v));
            }
            echo "done\n";
            PHP;

        $child = ChildPhp::spawn($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
        ]);

        $ready = fgets($child['pipes'][1]);
        Assert::string((string)$ready)->contains('ready');

        try {
            $reads = 0;
            $deadline = microtime(true) + 2.5;

            while (microtime(true) < $deadline) {
                $rows = $this->db->table('events')
                    ->where('grp', '=', 'a')
                    ->selectAllByArray();

                Assert::count($rows, 10);
                Assert::same(array_unique(array_column($rows, 'grp')), ['a']);
                Assert::count(array_unique(array_column($rows, 'seq')), 1);
                Assert::same(
                    $this->db->table('events')->where('grp', '=', 'a')->count(),
                    10,
                );
                $reads++;
            }

            Assert::int($reads)->greaterThan(0);
        } finally {
            $stdout = ChildPhp::drain($child);
        }

        Assert::string($stdout)->contains('done');
    }

    #[Test]
    public function fullScansSeeCompleteSetDuringUpdateChurn(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            echo "ready\n";
            fflush(STDOUT);
            $deadline = microtime(true) + 3.0;
            $i = 0;
            while (microtime(true) < $deadline) {
                $db->table('events')
                    ->where('grp', '=', 'a')
                    ->updateByArray(['seq' => ++$i % 100]);
            }
            echo "done\n";
            PHP;

        $child = ChildPhp::spawn($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
        ]);

        $ready = fgets($child['pipes'][1]);
        Assert::string((string)$ready)->contains('ready');

        try {
            $reads = 0;
            $deadline = microtime(true) + 2.5;

            while (microtime(true) < $deadline) {
                $this->db->invalidateCache('events');
                $rows = $this->db->table('events')->selectAllByArray();
                Assert::count($rows, 20);
                $reads++;
            }

            Assert::int($reads)->greaterThan(0);
        } finally {
            $stdout = ChildPhp::drain($child);
        }

        Assert::string($stdout)->contains('done');
    }

    #[Test]
    public function readReturnsAllCompleteLinesDespiteTornTail(): void
    {
        $dataPath = $this->dbDir . '/events/events.ndjson';
        file_put_contents($dataPath, '{"id":99,"grp":"to', FILE_APPEND);

        $storage = new NdjsonStorage($this->dbDir);
        $records = $storage->read('events', 'events.ndjson');

        Assert::count($records, 20);

        foreach ($records as $record) {
            Assert::true(\is_int($record['id']));
            Assert::true(\is_string($record['grp']));
        }
    }

    /**
     * @return array{proc: resource, pipes: array<int,resource>}
     */
    private function spawnFlockHolder(string $path): array
    {
        $dir = \dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $code = <<<'PHP'
            $h = fopen($argv[1], 'c');
            if ($h === false || !flock($h, LOCK_EX)) {
                fwrite(STDERR, "flock failed\n");
                exit(1);
            }
            echo "locked\n";
            fflush(STDOUT);
            fgets(STDIN);
            PHP;

        $child = ChildPhp::spawn($code, [$path]);
        $line = fgets($child['pipes'][1]);

        if ($line === false || !str_contains($line, 'locked')) {
            proc_terminate($child['proc'], 9);
            proc_close($child['proc']);
            Assert::fail('flock holder child failed to start');
        }

        return $child;
    }

    /**
     * @param array{proc: resource, pipes: array<int,resource>} $child
     */
    private function releaseHolder(array $child): void
    {
        fclose($child['pipes'][0]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['proc']);
    }

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-read-tests');
    }
}
