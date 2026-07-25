<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\InMemoryCache;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\CacheKeys;
use AV\JsonProvider\Tests\Support\RecordingCache;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for write-path read-modify-write discipline: every rewrite is based
 * on the on-disk state under table locks, never on the cache; concurrent
 * writers of one table serialize; DDL (db EX) and DML (db SH) serialize
 * without deadlock.
 */
final class ConcurrencyRmwTest
{
    private string $dbDir;

    private JsonDataProvider $db;

    private RecordingCache $recordingCache;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->recordingCache = new RecordingCache(new InMemoryCache());
        $this->db = JsonDataProvider::createDatabase(
            $this->dbDir,
            $this->recordingCache,
        );

        $this->db->createTable(TableSchema::create(
            name: 'items',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'name' => 'string', 'qty' => 'int'],
            indexes: [],
        ));

        foreach ([['a', 1], ['b', 2], ['c', 3]] as [$name, $qty]) {
            $this->db->insert('items', ['name' => $name, 'qty' => $qty]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function updateDoesNotReadFromCache(): void
    {
        $this->db->table('items')->selectAllByArray();
        $this->recordingCache->getCalls = 0;

        $this->db->table('items')
            ->where('name', '=', 'a')
            ->updateByArray(['qty' => 100]);

        Assert::same($this->recordingCache->getCalls, 0);
    }

    #[Test]
    public function deleteDoesNotReadFromCache(): void
    {
        $this->db->table('items')->selectAllByArray();
        $this->recordingCache->getCalls = 0;

        $this->db->table('items')->where('name', '=', 'b')->delete();

        Assert::same($this->recordingCache->getCalls, 0);
    }

    #[Test]
    public function updateOnPoisonedCacheKeepsForeignRow(): void
    {
        $this->db->table('items')->selectAllByArray();

        $this->recordingCache->set(
            CacheKeys::current($this->dbDir, 'items'),
            [['id' => 1, 'name' => 'a', 'qty' => 1]],
        );

        $this->insertExternally('items', ['name' => 'foreign', 'qty' => 42]);

        $this->db->table('items')
            ->where('name', '=', 'a')
            ->updateByArray(['qty' => 111]);

        $onDisk = $this->readDataFileNames();
        Assert::contains($onDisk, 'foreign');
        Assert::contains($onDisk, 'b');
        Assert::contains($onDisk, 'c');

        $foreign = $this->db->table('items')
            ->where('name', '=', 'foreign')
            ->selectAllByArray();
        Assert::count($foreign, 1);
        Assert::same($foreign[0]['qty'], 42);
    }

    #[Test]
    public function reorderColumnsOnPoisonedCacheKeepsForeignRow(): void
    {
        $this->db->table('items')->selectAllByArray();
        $this->recordingCache->set(
            CacheKeys::current($this->dbDir, 'items'),
            [['id' => 1, 'name' => 'a', 'qty' => 1]],
        );
        $this->insertExternally('items', ['name' => 'foreign', 'qty' => 7]);

        $this->db->reorderColumns('items', ['id', 'qty', 'name']);

        Assert::contains($this->readDataFileNames(), 'foreign');
        Assert::same(
            $this->db->columnNames('items'),
            ['id', 'qty', 'name'],
        );
    }

    #[Test]
    public function rebuildIndexUsesDiskNotCache(): void
    {
        $this->db->table('items')->selectAllByArray();
        $this->recordingCache->set(
            CacheKeys::current($this->dbDir, 'items'),
            [],
        );
        $this->recordingCache->getCalls = 0;

        $this->db->rebuildAllIndexes('items');

        Assert::same($this->recordingCache->getCalls, 0);
    }

    #[Test]
    public function concurrentInsertsAndUpdatesLoseNothing(): void
    {
        $inserts = 25;

        $code = <<<'PHP'
            require $argv[1];
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            echo "ready\n";
            fflush(STDOUT);
            for ($i = 0; $i < (int)$argv[3]; $i++) {
                $db->insert('items', ['name' => 'child' . $i, 'qty' => $i]);
            }
            echo "done\n";
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
            (string)$inserts,
        ]);

        $ready = fgets($child['pipes'][1]);
        Assert::string((string)$ready)->contains('ready');

        for ($i = 0; $i < 40; $i++) {
            $this->db->table('items')
                ->where('name', '=', 'a')
                ->updateByArray(['qty' => $i]);
        }

        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('done');

        $names = $this->readDataFileNames();

        for ($i = 0; $i < $inserts; $i++) {
            Assert::contains($names, 'child' . $i);
        }

        $this->db->invalidateCache('items');
        Assert::same($this->db->table('items')->count(), 3 + $inserts);
    }

    #[Test]
    public function createTableAndInsertsSerializeWithoutDeadlock(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            echo "ready\n";
            fflush(STDOUT);
            for ($i = 0; $i < 30; $i++) {
                $db->insert('items', ['name' => 'w' . $i, 'qty' => $i]);
            }
            echo "done\n";
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
        ]);

        $ready = fgets($child['pipes'][1]);
        Assert::string((string)$ready)->contains('ready');

        for ($i = 0; $i < 10; $i++) {
            $this->db->createTable(TableSchema::create(
                name: 'aux' . $i,
                uniqueConstraints: [],
                columns: ['id' => 'int'],
                indexes: [],
            ));
        }

        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('done');

        Assert::same($this->db->table('items')->count(), 33);

        for ($i = 0; $i < 10; $i++) {
            Assert::true($this->db->hasTable('aux' . $i));
        }
    }

    #[Test]
    public function insertAfterForeignMigrateUsesFreshSchema(): void
    {
        Assert::same(
            $this->db->columnNames('items'),
            ['id', 'name', 'qty'],
        );

        $code = <<<'PHP'
            require $argv[1];
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            $db->migrateColumns(\AV\JsonProvider\Schema\TableSchema::create(
                name: 'items',
                uniqueConstraints: [],
                columns: [
                    'id'   => 'int',
                    'name' => 'string',
                    'qty'  => 'int',
                    'flag' => 'int',
                ],
                indexes: [],
            ));
            $db->table('items')
                ->where('name', '=', 'a')
                ->updateByArray(['flag' => 7]);
            echo "done\n";
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
        ]);
        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('done');

        $id = $this->db->insert('items', [
            'name' => 'post-migrate',
            'qty'  => 1,
            'flag' => 3,
        ]);

        $raw = file_get_contents($this->dbDir . '/items/items.ndjson');
        \assert($raw !== false);
        Assert::string($raw)->contains('"flag":3');

        $this->db->invalidateCache('items');
        $fresh = $this->db->table('items')
            ->where('id', '=', $id)
            ->selectAllByArray();
        Assert::same($fresh[0]['flag'], 3);
    }

    #[Test]
    public function updateAfterForeignMigratePreservesMigratedColumn(): void
    {
        Assert::same($this->db->table('items')->count(), 3);

        $code = <<<'PHP'
            require $argv[1];
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            $db->migrateColumns(\AV\JsonProvider\Schema\TableSchema::create(
                name: 'items',
                uniqueConstraints: [],
                columns: [
                    'id'   => 'int',
                    'name' => 'string',
                    'qty'  => 'int',
                    'flag' => 'int',
                ],
                indexes: [],
            ));
            $db->table('items')->updateByArray(['flag' => 7]);
            echo "done\n";
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
        ]);
        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('done');

        $this->db->table('items')
            ->where('name', '=', 'b')
            ->updateByArray(['qty' => 99]);

        $raw = file_get_contents($this->dbDir . '/items/items.ndjson');
        \assert($raw !== false);
        Assert::same(substr_count($raw, '"flag":7'), 3);
    }

    #[Test]
    public function indexedSelectAfterForeignIndexDropFallsBackCleanly(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'indexed',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'grp' => 'string'],
            indexes: [
                new \AV\JsonProvider\Schema\IndexSchema(
                    name: 'idx_grp',
                    fields: [new \AV\JsonProvider\Schema\IndexFieldSchema(
                        'grp',
                        \AV\JsonProvider\Query\SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));
        $this->db->insert('indexed', ['grp' => 'a']);
        $this->db->insert('indexed', ['grp' => 'b']);

        Assert::count(
            $this->db->table('indexed')
                ->where('grp', '=', 'a')
                ->orderBy('grp', 'asc')
                ->selectAllByArray(),
            1,
        );

        $code = <<<'PHP'
            require $argv[1];
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            $db->migrateColumns(\AV\JsonProvider\Schema\TableSchema::create(
                name: 'indexed',
                uniqueConstraints: [],
                columns: ['id' => 'int', 'grp' => 'string', 'extra' => 'int'],
                indexes: [],
            ));
            echo "done\n";
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
        ]);
        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('done');

        $rows = $this->db->table('indexed')
            ->where('grp', '=', 'a')
            ->orderBy('grp', 'asc')
            ->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['grp'], 'a');
    }

    /**
     * Inserts through a real provider in a child process (its own meta and
     * index bookkeeping), so the parent's cache knows nothing about it.
     *
     * @param array<string,null|scalar> $record
     */
    private function insertExternally(string $table, array $record): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            $record = json_decode($argv[4], true);
            $db->insert($argv[3], $record);
            echo "done\n";
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
            $table,
            (string)json_encode($record),
        ]);

        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('done');
    }

    /**
     * Names present in the raw data file (bypassing provider and cache).
     *
     * @return list<string>
     */
    private function readDataFileNames(): array
    {
        $raw = file_get_contents($this->dbDir . '/items/items.ndjson');
        \assert($raw !== false);

        $names = [];

        foreach (explode("\n", trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (\is_array($decoded) && \is_string($decoded['name'] ?? null)) {
                $names[] = $decoded['name'];
            }
        }

        return $names;
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
        return TempDir::root('jp-rmw-tests');
    }
}
