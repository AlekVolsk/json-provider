<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\InMemoryCache;
use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Tests\Support\CacheKeys;
use AV\JsonProvider\Tests\Support\ChildPhp;
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
        TempDir::remove(self::dbPathRoot());

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
        TempDir::remove(self::dbPathRoot());
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
            } catch (\AV\JsonProvider\Exception\JsonProviderException $e) {
                echo 'ERR:' . $e->getErrorKey() . "\n";
            }
            PHP;

        $children = [];

        for ($i = 0; $i < 8; $i++) {
            $children[] = ChildPhp::spawn($code, [
                \dirname(__DIR__, 2) . '/vendor/autoload.php',
                $this->dbDir,
                self::TABLE,
                'dup',
            ]);
        }

        $wins = 0;
        $violations = 0;

        foreach ($children as $child) {
            $out = ChildPhp::drain($child);

            if (str_starts_with($out, 'OK:')) {
                $wins++;
            } elseif (str_starts_with($out, 'ERR:UniqueViolation')) {
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
            Assert::fail('a JsonProviderException was expected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'UniqueViolation');
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
            Assert::fail('a JsonProviderException was expected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'UniqueViolation');
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

        $child = ChildPhp::spawn($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
            self::TABLE,
            $codeValue,
        ]);

        $stdout = ChildPhp::drain($child);
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

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-unique-race-tests');
    }
}
