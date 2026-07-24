<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\InMemoryCache;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IssueCategory;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the version-tagged cache keys: a foreign write that changes
 * lineCount/byteSize makes every stale entry unreachable without any
 * invalidation message; a warm entry of the current state serves from
 * RAM; the documented same-size-update blind spot is closed manually by
 * invalidateCache().
 */
final class CacheCoherenceTest
{
    private const string DB_PATH = '/tmp/jp-cachecoh-tests';
    private const string TABLE = 'items';

    private string $dbDir;

    private InMemoryCache $cache;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
        $this->cache = new InMemoryCache();
        $this->db = JsonDataProvider::createDatabase(
            $this->dbDir,
            $this->cache,
        );

        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: ['id' => 'int', 'name' => 'string'],
        ));
        $this->db->insert(self::TABLE, ['name' => 'cat']);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    #[Test]
    public function foreignAppendIsVisibleWithoutInvalidation(): void
    {
        $this->db->table(self::TABLE)->selectAllByArray();

        // A foreign writer appends straight into the data file — no
        // provider, no invalidateCache. The size/lineCount change moves
        // the version tag, so the warm entry simply stops resolving.
        file_put_contents(
            $this->dataPath(),
            '{"id":2,"name":"foreign"}' . "\n",
            FILE_APPEND,
        );

        $rows = $this->db->table(self::TABLE)->selectAllByArray();

        Assert::count(
            $rows,
            2,
            'the stale warm entry must not shadow the foreign append',
        );
        Assert::same(
            $this->db->table(self::TABLE)->count(),
            2,
            'count must agree with the full scan',
        );
    }

    #[Test]
    public function warmEntryOfTheCurrentStateServesFromMemory(): void
    {
        $this->db->table(self::TABLE)->selectAllByArray();

        // A same-size content swap moves neither the meta lineCount nor
        // the physical size: the tag stays, and the read keeps serving
        // the warm entry — proof it never touched the disk.
        $swapped = str_replace(
            '"name":"cat"',
            '"name":"pig"',
            (string)file_get_contents($this->dataPath()),
        );
        file_put_contents($this->dataPath(), $swapped);

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::count($rows, 1);
        Assert::same($rows[0]['name'], 'cat');
    }

    #[Test]
    public function sameSizeUpdateBlindSpotIsClosedByInvalidateCache(): void
    {
        $this->db->table(self::TABLE)->selectAllByArray();

        // The documented residual window: a byte-for-byte-sized foreign
        // value swap moves neither lineCount nor byteSize, so the warm
        // entry keeps serving the old value...
        $swapped = str_replace(
            '"name":"cat"',
            '"name":"dog"',
            (string)file_get_contents($this->dataPath()),
        );
        file_put_contents($this->dataPath(), $swapped);

        $stale = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::same(
            $stale[0]['name'],
            'cat',
            'the same-size swap is the documented blind spot',
        );

        // ...and invalidateCache() is the manual escape hatch.
        $this->db->invalidateCache(self::TABLE);

        $fresh = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::same($fresh[0]['name'], 'dog');
    }

    #[Test]
    public function writeRefreshesTheCacheUnderTheNewTag(): void
    {
        // No select in between: the warm entry under the new tag comes
        // from the write path itself. The same-size swap proves the
        // subsequent read is a cache hit, not a disk read.
        $this->db->table(self::TABLE)
            ->where('name', '=', 'cat')
            ->updateByArray(['name' => 'fox']);

        $swapped = str_replace(
            '"name":"fox"',
            '"name":"owl"',
            (string)file_get_contents($this->dataPath()),
        );
        file_put_contents($this->dataPath(), $swapped);

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::same(
            $rows[0]['name'],
            'fox',
            'the read must hit the entry published by the write path',
        );
    }

    #[Test]
    public function abaDeleteInsertOfSameLengthByAnotherProcess(): void
    {
        // The A-B-A attack on a (lineCount, size)-only tag: a foreign
        // provider process deletes the row and inserts one of the same
        // byte length — both components return to their old values. The
        // rewrite lands on a fresh tmp+rename inode, so the tag must
        // move anyway.
        $this->db->table(self::TABLE)->selectAllByArray();

        $this->runExternally(
            '$db->table("items")->deleteById(1);'
                . '$db->insert("items", ["name" => "dog"]);',
        );

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::count($rows, 1);
        Assert::same(
            $rows[0]['name'],
            'dog',
            'the warm entry of the deleted state must not resurrect',
        );
        Assert::same($rows[0]['id'], 2);
    }

    #[Test]
    public function sameSizeUpdateByAnotherProviderProcessIsVisible(): void
    {
        // A same-length value update THROUGH the provider from another
        // process rewrites the file (new inode) — the warm entry of this
        // process must stop resolving even though size and lineCount are
        // unchanged.
        $this->db->table(self::TABLE)->selectAllByArray();

        $this->runExternally(
            '$db->table("items")->where("name", "=", "cat")'
                . '->updateByArray(["name" => "dog"]);',
        );

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::same(
            $rows[0]['name'],
            'dog',
            'a provider write from any process must move the tag',
        );
        Assert::same($this->db->table(self::TABLE)->count(), 1);
    }

    #[Test]
    public function dropRecreateDoesNotResurrectTheOldTable(): void
    {
        $this->db->table(self::TABLE)->selectAllByArray();

        $this->db->dropTable(self::TABLE);
        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: ['id' => 'int', 'name' => 'string'],
        ));
        $this->db->insert(self::TABLE, ['name' => 'dog']);

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::same(
            $rows[0]['name'],
            'dog',
            'the entry of the dropped table must not shadow the new one',
        );
    }

    #[Test]
    public function corruptMetaEntryDegradesReadsAndIsRepairable(): void
    {
        $metaPath = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($metaPath), true);
        \assert(\is_array($meta) && \is_array($meta[self::TABLE]));
        $meta[self::TABLE]['lineCount'] = '1';
        file_put_contents($metaPath, json_encode($meta));

        // Reads must survive (the tag degrades, the data comes from
        // disk) instead of dying with a bare TypeError.
        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::count($rows, 1);
        Assert::same($this->db->table(self::TABLE)->count(), 1);

        $report = $this->db->repairTable(self::TABLE);
        $corrupt = IssueCategory::META_ENTRY_CORRUPT;
        $found = array_filter(
            $report->issues,
            static fn ($i): bool => $i->category === $corrupt,
        );
        Assert::count($found, 1);

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::count($rows, 1);
    }

    #[Test]
    public function structurallyBrokenCachePayloadDegradesToAMiss(): void
    {
        $sanitize = new \ReflectionMethod(
            \AV\JsonProvider\Cache\RedisCache::class,
            'sanitizeRows',
        );

        Assert::same(
            $sanitize->invoke(null, [['a' => 1], 'scalar-row']),
            null,
            'a scalar "row" must fail the whole entry, not be trimmed',
        );
        Assert::same(
            $sanitize->invoke(null, [['a' => ['nested' => 1]]]),
            null,
            'a nested value must fail the whole entry',
        );
        Assert::same(
            $sanitize->invoke(null, [['a' => 1, 'b' => null]]),
            [['a' => 1, 'b' => null]],
        );
        Assert::same($sanitize->invoke(null, []), []);
    }

    // -- helpers -----------------------------------------------------------

    private function runExternally(string $body): void
    {
        $code = 'require $argv[1];'
            . '$db = \AV\JsonProvider\JsonDataProvider'
            . '::getInstance($argv[2]);'
            . $body
            . 'echo "done";';

        $cmd = [
            PHP_BINARY,
            '-r',
            $code,
            '--',
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
        ];
        $pipes = [];
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        \assert(\is_resource($proc));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        Assert::string($stdout === false ? '' : $stdout)
            ->contains('done');
    }

    private function dataPath(): string
    {
        return $this->dbDir . '/' . self::TABLE . '/' . self::TABLE
            . '.ndjson';
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
