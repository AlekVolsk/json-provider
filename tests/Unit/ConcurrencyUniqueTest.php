<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\InMemoryCache;
use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Tests\Support\CacheKeys;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the unique-check TOCTOU closure: the uniqueness probe and the
 * row commit sit inside one table-EX critical section, and the probe reads
 * the on-disk state (never the per-process cache). Invariant: no two rows
 * with equal non-null unique keys exist after any number of concurrent
 * writers.
 */
final class ConcurrencyUniqueTest
{
    private const string TABLE = 'codes';

    private string $dbDir;

    private JsonDataProvider $db;

    private InMemoryCache $cache;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->cache = new InMemoryCache();
        $this->db = JsonDataProvider::createDatabase(
            $this->dbDir,
            $this->cache,
        );

        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            uniqueConstraints: [new UniqueConstraint('uq_code', ['code'])],
            columns: ['id' => 'int', 'code' => 'string'],
            indexes: [],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function eightConcurrentInsertersYieldExactlyOneRow(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            try {
                $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
                $id = $db->insert($argv[3], ['code' => $argv[4]]);
                echo "OK:{$id}\n";
            } catch (\AV\JsonProvider\Exception\StorageException $e) {
                echo 'ERR:' . $e->getErrorKey() . "\n";
            }
            PHP;

        $children = [];

        for ($i = 0; $i < 8; $i++) {
            $children[] = $this->spawnPhp($code, [
                \dirname(__DIR__, 2) . '/vendor/autoload.php',
                $this->dbDir,
                self::TABLE,
                'dup',
            ]);
        }

        $wins = 0;
        $violations = 0;

        foreach ($children as $child) {
            $out = $this->drainAndClose($child);

            if (str_starts_with($out, 'OK:')) {
                $wins++;
            } elseif (str_starts_with($out, 'ERR:UNIQUE_VIOLATION')) {
                $violations++;
            }
        }

        Assert::same($wins, 1, 'exactly one inserter must win');
        Assert::same($violations, 7, 'the other seven must hit unique');
        Assert::count($this->linesWithCode('dup'), 1);
    }

    #[Test]
    public function insertSeesRowCommittedByAnotherProcess(): void
    {
        $this->db->insert(self::TABLE, ['code' => 'seed']);
        $this->db->table(self::TABLE)->selectAllByArray();

        $warmedKey = CacheKeys::current($this->dbDir, self::TABLE);

        $this->insertExternally('dup');

        $cached = $this->cache->get($warmedKey);
        Assert::notNull($cached);
        Assert::count($cached, 1, 'the cache must still be stale');

        try {
            $this->db->insert(self::TABLE, ['code' => 'dup']);
            Assert::fail('a StorageException was expected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'UNIQUE_VIOLATION');
        }

        Assert::count($this->linesWithCode('dup'), 1);
    }

    #[Test]
    public function insertSeesRawExternalAppend(): void
    {
        $this->db->insert(self::TABLE, ['code' => 'seed']);
        $this->db->table(self::TABLE)->selectAllByArray();

        file_put_contents(
            $this->dataPath(),
            '{"id":99,"code":"dup"}' . "\n",
            FILE_APPEND,
        );

        try {
            $this->db->insert(self::TABLE, ['code' => 'dup']);
            Assert::fail('a StorageException was expected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'UNIQUE_VIOLATION');
        }

        Assert::count($this->linesWithCode('dup'), 1);
    }

    /**
     * Inserts through a real provider in a child process (own meta and
     * index bookkeeping), so the parent's cache knows nothing about it.
     */
    private function insertExternally(string $codeValue): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            $db->insert($argv[3], ['code' => $argv[4]]);
            echo "done\n";
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
            self::TABLE,
            $codeValue,
        ]);

        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('done');
    }

    /**
     * @return list<string>
     */
    private function linesWithCode(string $value): array
    {
        $raw = file_get_contents($this->dataPath());
        \assert($raw !== false);

        $hits = [];

        foreach (explode("\n", trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (\is_array($decoded) && ($decoded['code'] ?? null) === $value) {
                $hits[] = $line;
            }
        }

        return $hits;
    }

    private function dataPath(): string
    {
        return $this->dbDir . '/' . self::TABLE . '/' . self::TABLE
            . '.ndjson';
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

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-unique-race-tests');
    }
}
