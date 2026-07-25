<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the insert lifecycle and crash self-healing (meta v2 with
 * byteSize): allocateId/commitAppend split, repairTail on a torn append,
 * the ensureTableConsistent gate on the next write, lazy v1->v2 meta
 * upgrade, and index catch-up after a foreign append.
 */
final class CrashRecoveryTest
{
    private const string TABLE = 'events';

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->db = JsonDataProvider::createDatabase(
            self::dbPathRoot() . '/' . uniqid('db', true),
        );

        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            uniqueConstraints: [],
            columns: ['id' => 'int', 'name' => 'string', 'rank' => 'int'],
            indexes: [
                new IndexSchema(
                    name: 'idx_rank',
                    fields: [new IndexFieldSchema(
                        'rank',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));

        foreach ([['a', 30], ['b', 20], ['c', 10]] as [$name, $rank]) {
            $this->db->insert(self::TABLE, [
                'name' => $name,
                'rank' => $rank,
            ]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function insertCommitsLineCountAndByteSize(): void
    {
        $meta = $this->readMeta();

        Assert::same($meta[self::TABLE]['lastInsertedId'], 3);
        Assert::same($meta[self::TABLE]['lineCount'], 3);
        Assert::same(
            $meta[self::TABLE]['byteSize'] ?? null,
            filesize($this->dataPath()),
        );
    }

    #[Test]
    public function failedInsertProbeLeavesMetaUntouched(): void
    {
        $before = $this->readMeta();

        $caught = null;

        try {
            $this->db->insert(self::TABLE, [
                'name' => "\xC3\x28",
                'rank' => 1,
            ]);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($this->readMeta(), $before);

        $id = $this->db->insert(self::TABLE, ['name' => 'd', 'rank' => 40]);
        Assert::same($id, 4);
    }

    #[Test]
    public function lineCountDriftHealsOnNextInsert(): void
    {
        $this->patchMeta(static function (array $entry): array {
            $entry['lineCount']++;
            $entry['byteSize'] = ($entry['byteSize'] ?? 0) + 10;

            return $entry;
        });

        $id = $this->db->insert(self::TABLE, ['name' => 'd', 'rank' => 40]);
        Assert::same($id, 4);

        $meta = $this->readMeta();
        Assert::same($meta[self::TABLE]['lineCount'], 4);
        Assert::same(
            $meta[self::TABLE]['byteSize'] ?? null,
            filesize($this->dataPath()),
        );

        $this->assertIndexMatchesFullScan();
    }

    #[Test]
    public function validJsonTailWithoutNewlineIsSavedOnNextInsert(): void
    {
        file_put_contents(
            $this->dataPath(),
            '{"id":99,"name":"crashed","rank":5}',
            FILE_APPEND,
        );

        $this->db->insert(self::TABLE, ['name' => 'd', 'rank' => 40]);

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::count($rows, 5);

        $crashed = $this->db->table(self::TABLE)
            ->where('id', '=', 99)
            ->selectAllByArray();
        Assert::count($crashed, 1);
        Assert::same($crashed[0]['name'], 'crashed');

        $fresh = $this->db->table(self::TABLE)
            ->where('name', '=', 'd')
            ->selectAllByArray();
        Assert::count($fresh, 1);
        Assert::same($fresh[0]['rank'], 40);

        $this->assertIndexMatchesFullScan();
    }

    #[Test]
    public function invalidTailIsTruncatedOnNextInsert(): void
    {
        file_put_contents(
            $this->dataPath(),
            '{"id":99,"name":"tor',
            FILE_APPEND,
        );

        $this->db->insert(self::TABLE, ['name' => 'd', 'rank' => 40]);

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::count($rows, 4);

        $ids = array_column($rows, 'id');
        sort($ids);
        Assert::same($ids, [1, 2, 3, 4]);

        $raw = (string)file_get_contents($this->dataPath());
        Assert::false(str_contains($raw, 'tor'));

        $this->assertIndexMatchesFullScan();
    }

    #[Test]
    public function repairTailDirectlyReportsActions(): void
    {
        $storage = new NdjsonStorage($this->dbPath());
        $file = self::TABLE . '.ndjson';

        $clean = $storage->repairTail(self::TABLE, $file);
        Assert::same($clean['action'], 'none');
        Assert::same($clean['size'], filesize($this->dataPath()));
        Assert::same($clean['lines'], 3);

        file_put_contents(
            $this->dataPath(),
            '{"id":9,"name":"x","rank":1}',
            FILE_APPEND,
        );
        clearstatcache();
        $saved = $storage->repairTail(self::TABLE, $file);
        Assert::same($saved['action'], 'newline-added');
        Assert::same($saved['lines'], 4);
        clearstatcache();
        Assert::same($saved['size'], filesize($this->dataPath()));

        file_put_contents($this->dataPath(), '{"broken', FILE_APPEND);
        clearstatcache();
        $truncated = $storage->repairTail(self::TABLE, $file);
        Assert::same($truncated['action'], 'partial-truncated');
        Assert::same($truncated['lines'], 4);
        clearstatcache();
        Assert::same($truncated['size'], filesize($this->dataPath()));
    }

    #[Test]
    public function foreignAppendIsAbsorbedAndIndexRebuilt(): void
    {
        file_put_contents(
            $this->dataPath(),
            '{"id":50,"name":"foreign","rank":25}' . "\n",
            FILE_APPEND,
        );

        $this->db->insert(self::TABLE, ['name' => 'd', 'rank' => 40]);

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::count($rows, 5);

        $meta = $this->readMeta();
        Assert::same($meta[self::TABLE]['lineCount'], 5);
        Assert::same(
            $meta[self::TABLE]['byteSize'] ?? null,
            filesize($this->dataPath()),
        );

        $this->assertIndexMatchesFullScan();
    }

    #[Test]
    public function metaV1WithoutByteSizeIsUpgradedOnFirstInsert(): void
    {
        $this->patchMeta(static function (array $entry): array {
            unset($entry['byteSize']);

            return $entry;
        });

        $this->db->insert(self::TABLE, ['name' => 'd', 'rank' => 40]);

        $meta = $this->readMeta();
        Assert::true(\array_key_exists('byteSize', $meta[self::TABLE]));
        Assert::same(
            $meta[self::TABLE]['byteSize'],
            filesize($this->dataPath()),
        );

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::count($rows, 4);
    }

    #[Test]
    public function allocateIdDoesNotTouchLineCountOrByteSize(): void
    {
        $before = $this->readMeta();

        $storage = new JsonStorage($this->dbPath());
        $meta = new \AV\JsonProvider\Registry\MetaRegistry($storage);
        $id = $meta->allocateId(self::TABLE);

        Assert::same($id, 4);

        $after = $this->readMeta();
        Assert::same(
            $after[self::TABLE]['lineCount'],
            $before[self::TABLE]['lineCount'],
        );
        Assert::same(
            $after[self::TABLE]['byteSize'] ?? null,
            $before[self::TABLE]['byteSize'] ?? null,
        );
        Assert::same($after[self::TABLE]['lastInsertedId'], 4);
    }

    #[Test]
    public function idGapFromUncommittedAllocateIsAcceptedBySequence(): void
    {
        $storage = new JsonStorage($this->dbPath());
        $meta = new \AV\JsonProvider\Registry\MetaRegistry($storage);
        $meta->allocateId(self::TABLE);

        $id = $this->db->insert(self::TABLE, ['name' => 'd', 'rank' => 40]);

        Assert::same($id, 5);

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::count($rows, 4);
    }

    private function dbPath(): string
    {
        $reflection = new \ReflectionProperty(
            JsonDataProvider::class,
            'dbPath',
        );
        $path = $reflection->getValue($this->db);

        if (!\is_string($path)) {
            throw new \RuntimeException('dbPath must be a string');
        }

        return $path;
    }

    private function dataPath(): string
    {
        return $this->dbPath() . '/' . self::TABLE . '/'
            . self::TABLE . '.ndjson';
    }

    /**
     * @return array<string,array{
     *     lastInsertedId: int,
     *     lineCount: int,
     *     byteSize?: int
     * }>
     */
    private function readMeta(): array
    {
        $raw = file_get_contents($this->dbPath() . '/meta.json');
        \assert($raw !== false);

        /**
         * @var array<string,array{
         *     lastInsertedId: int,
         *     lineCount: int,
         *     byteSize?: int
         * }> $decoded
         */
        $decoded = json_decode($raw, true);
        ksort($decoded);

        return $decoded;
    }

    /**
     * @param callable(array{
     *     lastInsertedId: int,
     *     lineCount: int,
     *     byteSize?: int
     * }): array<string,int> $patch
     */
    private function patchMeta(callable $patch): void
    {
        $meta = $this->readMeta();
        $meta[self::TABLE] = $patch($meta[self::TABLE]);

        file_put_contents(
            $this->dbPath() . '/meta.json',
            json_encode($meta, JSON_PRETTY_PRINT),
        );
    }

    /**
     * The idx_rank index path must agree with a full scan after healing.
     */
    private function assertIndexMatchesFullScan(): void
    {
        $viaIndex = $this->db->table(self::TABLE)
            ->orderBy('rank', 'asc')
            ->selectAllByArray();

        $fullScan = $this->db->table(self::TABLE)->selectAllByArray();
        usort(
            $fullScan,
            static function (array $a, array $b): int {
                \assert(\is_int($a['rank']) && \is_int($b['rank']));

                return $a['rank'] <=> $b['rank'];
            },
        );

        Assert::same(
            array_column($viaIndex, 'id'),
            array_column($fullScan, 'id'),
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
        return TempDir::root('jp-crash-tests');
    }
}
