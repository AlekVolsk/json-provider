<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\NdjsonStorage;
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
    private const string DB_PATH = '/tmp/jp-read-tests';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
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
        $this->removeDir(self::DB_PATH);
    }

    #[Test]
    public function fullScanIsLockFreeEvenUnderForeignTableEx(): void
    {
        // A foreign process holds the table EX lock; a full scan must not
        // block on it (level 1 of the reader model takes no locks at all).
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
        // The child endlessly deletes and re-inserts the 'b' group, which
        // rewrites the data file and shifts line numbers. Every indexed
        // select for 'a' must return exactly the 'a' rows: a torn
        // index/data pair would leak 'b' rows or lose 'a' rows.
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

        $child = $this->spawnPhp($code, [
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
            $stdout = $this->drainAndClose($child);
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

        $child = $this->spawnPhp($code, [
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
            $stdout = $this->drainAndClose($child);
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

    // -- helpers -----------------------------------------------------------

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

        $child = $this->spawnPhp($code, [$path]);
        $line = fgets($child['pipes'][1]);

        if ($line === false || !str_contains($line, 'locked')) {
            proc_terminate($child['proc'], 9);
            proc_close($child['proc']);
            Assert::fail('flock holder child failed to start');
        }

        return $child;
    }

    /**
     * @param list<string> $args
     *
     * @return array{proc: resource, pipes: array<int,resource>}
     */
    private function spawnPhp(string $code, array $args): array
    {
        $cmd = array_merge([PHP_BINARY, '-r', $code, '--'], $args);
        $pipes = [];
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        \assert(\is_resource($proc));

        return ['proc' => $proc, 'pipes' => $pipes];
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

    /**
     * @param array{proc: resource, pipes: array<int,resource>} $child
     */
    private function drainAndClose(array $child): string
    {
        fclose($child['pipes'][0]);
        $stdout = stream_get_contents($child['pipes'][1]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['proc']);

        return $stdout === false ? '' : $stdout;
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);

            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }
}
