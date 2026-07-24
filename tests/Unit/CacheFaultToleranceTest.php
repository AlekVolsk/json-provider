<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\RedisCache;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\SpyLogger;
use AV\JsonProvider\Tests\Support\ThrowingRedis;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the cache degradation policy on a down Redis backend: every
 * database operation keeps working from disk, nothing throws through,
 * and the degradation is reported to the PSR-3 logger at warning level.
 */
final class CacheFaultToleranceTest
{
    private const string DB_PATH = '/tmp/jp-cachefault-tests';

    private string $dbDir;

    private SpyLogger $logger;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
        $this->logger = new SpyLogger();
        $this->db = JsonDataProvider::createDatabase(
            $this->dbDir,
            new RedisCache(new ThrowingRedis(), 0, $this->logger),
        );

        $this->db->createTable(TableSchema::create(
            name: 'items',
            columns: ['id' => 'int', 'name' => 'string'],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    #[Test]
    public function readsFallBackToDiskAndLogTheDegradation(): void
    {
        $this->db->insert('items', ['name' => 'a']);

        $rows = $this->db->table('items')->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['name'], 'a');

        $warnings = array_filter(
            $this->logger->records,
            static fn (array $r): bool => $r['level'] === 'warning',
        );
        Assert::true(
            $warnings !== [],
            'the degradation must reach the logger at warning level',
        );
    }

    #[Test]
    public function mutationsCompleteDespiteTheDeadBackend(): void
    {
        $id = $this->db->insert('items', ['name' => 'a']);
        $this->db->table('items')
            ->where('id', '=', $id)
            ->updateByArray(['name' => 'b']);
        $this->db->table('items')->deleteById($id);

        Assert::same($this->db->table('items')->count(), 0);
    }

    #[Test]
    public function directAdapterCallsFollowThePolicy(): void
    {
        $cache = new RedisCache(new ThrowingRedis(), 5, $this->logger);

        Assert::same($cache->get('k'), null, 'get degrades to a miss');
        $cache->set('k', [['id' => 1]]);
        $cache->invalidate('k');
        $cache->flushDb('jdp:2:aaaa:');

        Assert::count(
            array_filter(
                $this->logger->records,
                static fn (array $r): bool => $r['level'] === 'warning',
            ),
            4,
            'each degraded operation logs exactly once',
        );
    }

    #[Test]
    public function flushDbSurvivesTheDeadBackend(): void
    {
        $this->db->flushDb();

        $warnings = array_filter(
            $this->logger->records,
            static fn (array $r): bool => $r['level'] === 'warning',
        );
        Assert::count(
            $warnings,
            1,
            'flushDb must degrade silently and log once',
        );
    }

    // -- helpers -----------------------------------------------------------

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
