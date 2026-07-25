<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\InMemoryCache;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the namespaced cache keys: two databases sharing one cache
 * backend must never read each other's rows, and flushing one database
 * touches only its own namespace.
 */
final class CacheNamespaceTest
{
    private string $dirA;

    private string $dirB;

    private InMemoryCache $shared;

    private JsonDataProvider $dbA;

    private JsonDataProvider $dbB;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->shared = new InMemoryCache();
        $this->dirA = self::dbPathRoot() . '/' . uniqid('a', true);
        $this->dirB = self::dbPathRoot() . '/' . uniqid('b', true);
        $this->dbA = JsonDataProvider::createDatabase(
            $this->dirA,
            $this->shared,
        );
        $this->dbB = JsonDataProvider::createDatabase(
            $this->dirB,
            $this->shared,
        );

        foreach ([$this->dbA, $this->dbB] as $db) {
            $db->createTable(TableSchema::create(
                name: 'users',
                columns: ['id' => 'int', 'name' => 'string'],
            ));
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function twoDatabasesOnOneBackendStayIsolated(): void
    {
        $this->dbA->insert('users', ['name' => 'ALICE_FROM_A']);
        $this->dbB->insert('users', ['name' => 'CAROL_FROM_B']);

        $this->dbA->table('users')->selectAllByArray();
        $this->dbB->table('users')->selectAllByArray();

        $fromB = $this->dbB->table('users')->selectAllByArray();
        Assert::count($fromB, 1);
        Assert::same(
            $fromB[0]['name'],
            'CAROL_FROM_B',
            'database B must never see rows cached by database A',
        );

        $fromA = $this->dbA->table('users')->selectAllByArray();
        Assert::count($fromA, 1);
        Assert::same($fromA[0]['name'], 'ALICE_FROM_A');
    }

    #[Test]
    public function flushDbScopesToOneDatabase(): void
    {
        $this->dbA->insert('users', ['name' => 'a']);
        $this->dbB->insert('users', ['name' => 'b']);
        $this->dbA->table('users')->selectAllByArray();
        $this->dbB->table('users')->selectAllByArray();

        $this->shared->set('someone-elses-key', [['id' => 1]]);

        $this->dbA->flushDb();

        Assert::notNull(
            $this->shared->get('someone-elses-key'),
            'flushDb must never touch keys outside the jdp namespace',
        );

        $bFile = $this->dirB . '/users/users.ndjson';
        file_put_contents($bFile, str_replace(
            '"name":"b"',
            '"name":"z"',
            (string)file_get_contents($bFile),
        ));

        $fromB = $this->dbB->table('users')->selectAllByArray();
        Assert::same(
            $fromB[0]['name'],
            'b',
            "B's warm cache entry must survive A's flushDb",
        );
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
        return TempDir::root('jp-cachens-tests');
    }
}
