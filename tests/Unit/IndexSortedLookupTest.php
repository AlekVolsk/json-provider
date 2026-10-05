<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\StorageManifest;
use AV\JsonProvider\Tests\Support\CompatFixture;
use AV\JsonProvider\Tests\Support\EngineAccess;
use AV\JsonProvider\Tests\Support\EngineProcess;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Index lookups that search the sorted head of an index file in place and
 * read data lines through their offsets (see DerivedFiles).
 *
 * A lookup reads about log2(n) index lines plus the run it returns and the
 * appended tail, checks every line it reads, and checks every record it
 * returns against the entry that pointed at it. Damage off its path is
 * left to validate(). Whenever the derived files do not describe the index
 * and data files as they are, the lookup reads the whole index file as
 * before — never a wrong row.
 *
 * @phpstan-type Entry array{key:string, line:int}
 */
final class IndexSortedLookupTest
{
    private const string TABLE = 'items';
    private const string TWIN = 'twins';

    private string $root;

    private string $dbDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-index-sorted-lookup');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * Every operator over every key kind answers exactly what a full scan
     * of an unindexed twin with the same rows answers — over a fully sorted
     * index and over one with an appended tail.
     */
    #[Test]
    public function lookupsMatchFullScanOfTwin(): void
    {
        $db = JsonDataProvider::createDatabase($this->dbDir);
        $columns = [
            'n' => ColumnTypes::INT,
            'f' => ColumnTypes::FLOAT,
            's' => ColumnTypes::STRING,
        ];
        $db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: $columns,
            indexes: [
                self::index('idx_n', ['n' => SortDirectionEnum::ASC]),
                self::index('idx_f', ['f' => SortDirectionEnum::DESC]),
                self::index('idx_s', ['s' => SortDirectionEnum::ASC]),
                self::index('idx_ns', [
                    'n' => SortDirectionEnum::ASC,
                    's' => SortDirectionEnum::DESC,
                ]),
            ],
        ));
        $db->createTable(TableSchema::create(
            name: self::TWIN,
            columns: $columns,
        ));

        mt_srand(20261005);
        $rows = [];

        for ($id = 1; $id <= 300; $id++) {
            $rows[] = self::randomRow($id);
        }

        $db->importRecords(self::TABLE, $rows);
        $db->importRecords(self::TWIN, $rows);
        $this->assertMatchesTwin($db);

        for ($i = 0; $i < 40; $i++) {
            $row = self::randomRow(0);
            unset($row['id']);
            $db->insert(self::TABLE, $row);
            $db->insert(self::TWIN, $row);
        }

        $this->assertMatchesTwin($db);
    }

    /**
     * The lookup does not read what is off its path: a damaged entry far
     * from the target changes nothing for it, and validate() still finds
     * the damage.
     */
    #[Test]
    public function damageOffThePathIsLeftToValidate(): void
    {
        $db = $this->numbers(200);
        $this->editIndexLine(
            150,
            static fn (array $entry): array => [
                'key'  => str_repeat('z', \strlen($entry['key'])),
                'line' => $entry['line'],
            ],
        );

        Assert::same($this->ids($db, 'n', '=', 5), [6]);
        Assert::true($db->validate()->hasErrors());
    }

    /**
     * An entry on the path pointing at another row is caught by the check
     * of the returned record against its entry.
     */
    #[Test]
    public function entryPointingAtAnotherRowIsLoud(): void
    {
        $db = $this->numbers(9);
        $this->editIndexLine(
            5,
            static fn (array $entry): array => [
                'key'  => $entry['key'],
                'line' => 6,
            ],
        );

        $this->assertUnreliable(
            fn () => $this->ids($db, 'n', '=', 5),
            'IndexRecordMismatch',
        );
    }

    #[Test]
    public function malformedKeyOnThePathIsLoud(): void
    {
        $db = $this->numbers(1);
        $this->editIndexLine(
            0,
            static fn (array $entry): array => [
                'key'  => str_repeat('z', \strlen($entry['key'])),
                'line' => $entry['line'],
            ],
        );

        $this->assertUnreliable(
            fn () => $this->ids($db, 'n', '=', 0),
            'IndexKeyMalformed',
        );
    }

    /**
     * Keys out of order on the path of a range break the bounds the search
     * has already seen.
     */
    #[Test]
    public function keysOutOfOrderOnThePathAreLoud(): void
    {
        $db = $this->numbers(10);
        $path = $this->indexPath('idx_n');
        $lines = explode("\n", rtrim((string)file_get_contents($path)));
        [$lines[1], $lines[2]] = [$lines[2], $lines[1]];
        file_put_contents($path, implode("\n", $lines) . "\n");

        $this->assertUnreliable(
            fn () => $this->ids($db, 'n', 'BETWEEN', [0, 2]),
            'IndexOrderBroken',
        );
    }

    #[Test]
    public function lostTailEntryIsLoud(): void
    {
        $db = $this->numbers(4);
        $db->insert(self::TABLE, ['n' => 9]);
        $path = $this->indexPath('idx_n');
        $lines = explode("\n", rtrim((string)file_get_contents($path)));
        array_pop($lines);
        file_put_contents($path, implode("\n", $lines) . "\n");

        $this->assertUnreliable(
            fn () => $this->ids($db, 'n', '=', 9),
            'IndexCountMismatch',
        );
    }

    /**
     * Past the tail limit an append rewrites the index file sorted, so the
     * whole file is the sorted head again.
     */
    #[Test]
    public function longTailIsMergedIntoTheHead(): void
    {
        $db = $this->numbers(10);
        EngineAccess::set(
            EngineAccess::part($db, 'store'),
            'indexTailLimit',
            3,
        );

        for ($n = 100; $n < 103; $n++) {
            $db->insert(self::TABLE, ['n' => $n]);
        }

        $head = $this->head('idx_n');
        Assert::true($head !== null);
        Assert::same($head['count'], 10);

        $db->insert(self::TABLE, ['n' => 103]);
        $head = $this->head('idx_n');

        Assert::true($head !== null);
        Assert::same($head['count'], 14);
        Assert::same(
            $head['bytes'],
            \strlen((string)file_get_contents($this->indexPath('idx_n'))),
        );
        Assert::same($this->ids($db, 'n', '>=', 102), [13, 14]);
    }

    /**
     * An index file replaced behind the engine's back — another inode, or
     * the same inode number handed out again with other bytes at the end of
     * the head — voids its recorded head: the lookup reads and checks the
     * whole file again, and so meets damage far from its target.
     */
    #[Test]
    public function replacedIndexFileFallsBackToWholeRead(): void
    {
        $db = $this->numbers(20);
        $path = $this->indexPath('idx_n');
        copy($path, $path . '.copy');
        rename($path . '.copy', $path);

        Assert::same($this->ids($db, 'n', 'IN', [3, 17]), [4, 18]);

        $lines = explode("\n", rtrim((string)file_get_contents($path)));
        $lines[19] = str_replace('"line":19', '"line":18', $lines[19]);
        file_put_contents($path . '.copy', implode("\n", $lines) . "\n");
        rename($path . '.copy', $path);

        $this->assertUnreliable(
            fn () => $this->ids($db, 'n', '=', 3),
            'IndexBrokenPermutation',
        );
    }

    /**
     * Line offsets that no longer describe the data file are not used; the
     * lookup walks the file instead.
     */
    #[Test]
    public function staleLineOffsetsAreNotUsed(): void
    {
        $db = $this->numbers(50);
        unlink($this->derivedPath('.offsets'));

        Assert::same($this->ids($db, 'n', '=', 42), [43]);

        $db->table(self::TABLE)->where('n', '=', 0)->updateByArray(['n' => 0]);

        Assert::true(is_file($this->derivedPath('.offsets')));
        Assert::same($this->ids($db, 'n', '=', 42), [43]);
    }

    /**
     * Offsets that describe another file under the same inode — the data
     * file rewritten in place with a line made longer and its counters
     * committed, as an older engine leaves it when the inode is handed out
     * again — are not extended by an append: lookups and pages answer from
     * the data as stored.
     */
    #[Test]
    public function offsetsOfAnotherFileAreNotExtended(): void
    {
        $db = $this->numbers(50);
        $data = $this->dbDir . '/' . self::TABLE . '/' . self::TABLE
            . '.ndjson';
        $lines = explode("\n", (string)file_get_contents($data));
        $lines[4] = '{"id": 5,"n":4}';
        file_put_contents($data, implode("\n", $lines));
        clearstatcache();
        $metaPath = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($metaPath), true);
        Assert::true(\is_array($meta) && \is_array($meta[self::TABLE]));
        $meta[self::TABLE]['byteSize'] = filesize($data);
        file_put_contents($metaPath, json_encode($meta, JSON_THROW_ON_ERROR));

        $db->insert(self::TABLE, ['n' => 1000]);

        foreach ([30 => 31, 42 => 43, 49 => 50, 1000 => 51] as $n => $id) {
            Assert::same($this->ids($db, 'n', '=', $n), [$id]);
        }

        Assert::same(
            array_column(
                $db->table(self::TABLE)->orderBy('n')->limit(3)->offset(20)
                    ->selectAllByArray(),
                'n',
            ),
            [20, 21, 22],
        );
    }

    #[Test]
    public function generationOneKeepsNoDerivedFiles(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);
        $db = JsonDataProvider::getInstance($this->dbDir);

        set_error_handler(static fn (): bool => true, E_USER_DEPRECATED);

        try {
            $db->table(CompatFixture::OWNERS)->insertByArray([
                'name'  => 'x',
                'email' => 'x@example.com',
            ]);
        } finally {
            restore_error_handler();
        }

        Assert::false(is_dir($this->dbDir . '/' . StorageManifest::DIR));
    }

    #[Test]
    public function derivedFilesFollowRenameAndDrop(): void
    {
        $db = $this->numbers(10);
        $db->renameTable(self::TABLE, 'renamed');

        Assert::false(is_file($this->derivedPath('.offsets')));
        Assert::true(is_file($this->derivedPath('.offsets', 'renamed')));
        Assert::same(
            array_column(
                $db->table('renamed')->where('n', '=', 7)->selectAllByArray(),
                'id',
            ),
            [8],
        );
        Assert::true($db->storageStatus()->isCurrent());

        $db->dropTable('renamed');

        Assert::false(is_file($this->derivedPath('.offsets', 'renamed')));
        Assert::false(is_file($this->derivedPath('.indexes.json', 'renamed')));
    }

    /**
     * A generation-2 database without the derived files (as 1.1 leaves it)
     * reports what a migration would add, and the migration builds them.
     */
    #[Test]
    public function migrationBuildsMissingDerivedFiles(): void
    {
        $this->numbers(10);
        $this->dropDerivedFiles();
        $db = $this->reopen();

        $status = $db->storageStatus();

        Assert::false($status->isCurrent());
        Assert::same($status->pendingTables, [self::TABLE]);
        Assert::same($status->pendingFeatures, StorageManifest::FEATURES);

        $report = $db->migrateStorage();

        Assert::same($report->steps, []);
        Assert::same($report->refreshedTables, [self::TABLE]);
        Assert::true($db->storageStatus()->isCurrent());
        Assert::same(
            StorageManifest::read($this->dbDir)->compat,
            StorageManifest::FEATURES,
        );
        Assert::same($this->ids($db, 'n', '=', 4), [5]);
    }

    /**
     * The 1.0 engine does not see the derived files; after it writes, the
     * lookups of the current engine still return its rows, and a migration
     * makes the files current again.
     */
    #[Test]
    public function writesOfTheOlderEngineNeverYieldWrongRows(): void
    {
        EngineProcess::current($this->dbDir, [['op' => 'create']]);
        EngineProcess::legacy($this->dbDir, [
            [
                'op'     => 'insert',
                'table'  => CompatFixture::ITEMS,
                'record' => ['ownerId' => 2, 'title' => 'legacy'],
            ],
            [
                'op'    => 'update',
                'table' => CompatFixture::ITEMS,
                'id'    => 1,
                'patch' => ['ownerId' => 2],
            ],
        ]);
        $db = JsonDataProvider::getInstance($this->dbDir);
        $expected = EngineProcess::legacy($this->dbDir, [
            [
                'op'    => 'where',
                'table' => CompatFixture::ITEMS,
                'field' => 'ownerId',
                'value' => 2,
            ],
        ])[0];

        Assert::same(
            $db->table(CompatFixture::ITEMS)->where('ownerId', '=', 2)
                ->orderBy('id')->selectAllByArray(),
            $expected,
        );

        $db->migrateStorage();

        Assert::true($db->storageStatus()->isCurrent());
        Assert::same(
            $db->table(CompatFixture::ITEMS)->where('ownerId', '=', 2)
                ->orderBy('id')->selectAllByArray(),
            $expected,
        );
    }

    private function assertMatchesTwin(JsonDataProvider $db): void
    {
        $values = [
            'n' => [-3, 0, 7, 49, 50, 51, 99, 1000],
            'f' => [-2.5, 0.0, 1.5, 24.75, 50.0, 99.0, 1e6],
            's' => ['', 'a', 'k', 'kz', 'm', 'zz'],
        ];

        foreach ($values as $field => $set) {
            foreach ($set as $value) {
                foreach (['=', '>', '>=', '<', '<='] as $operator) {
                    $this->assertSame($db, $field, $operator, $value);
                }
            }

            $this->assertSame($db, $field, 'IN', \array_slice($set, 1, 4));
            $this->assertSame($db, $field, 'BETWEEN', [$set[1], $set[4]]);
        }
    }

    private function assertSame(
        JsonDataProvider $db,
        string $field,
        string $operator,
        mixed $value,
    ): void {
        Assert::same(
            $this->ids($db, $field, $operator, $value),
            $this->ids($db, $field, $operator, $value, self::TWIN),
            $field . ' ' . $operator . ' ' . json_encode($value),
        );
    }

    /**
     * @return list<int>
     */
    private function ids(
        JsonDataProvider $db,
        string $field,
        string $operator,
        mixed $value,
        string $table = self::TABLE,
    ): array {
        $ids = [];

        foreach (
            $db->table($table)->where($field, $operator, $value)
                ->selectAllByArray() as $row
        ) {
            Assert::true(\is_int($row['id']));
            $ids[] = $row['id'];
        }

        sort($ids);

        return $ids;
    }

    /**
     * A table of $rows rows with n = 0..$rows-1 under an index on n, loaded
     * whole, so the index file is one sorted head.
     */
    private function numbers(int $rows): JsonDataProvider
    {
        $db = JsonDataProvider::createDatabase($this->dbDir);
        $db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: ['n' => ColumnTypes::INT],
            indexes: [self::index('idx_n', ['n' => SortDirectionEnum::ASC])],
        ));
        $records = [];

        for ($n = 0; $n < $rows; $n++) {
            $records[] = ['id' => $n + 1, 'n' => $n];
        }

        $db->importRecords(self::TABLE, $records);

        return $db;
    }

    /**
     * Rewrites one line of the index file in place with an edit that keeps
     * its length, so the recorded sorted head stays valid.
     *
     * @param \Closure(Entry): Entry $edit
     */
    private function editIndexLine(int $line, \Closure $edit): void
    {
        $path = $this->indexPath('idx_n');
        $lines = explode("\n", rtrim((string)file_get_contents($path)));
        $entry = json_decode($lines[$line], true);
        Assert::true(
            \is_array($entry)
            && \is_string($entry['key'] ?? null)
            && \is_int($entry['line'] ?? null),
        );
        $edited = (string)json_encode($edit([
            'key'  => $entry['key'],
            'line' => $entry['line'],
        ]));
        Assert::same(\strlen($edited), \strlen($lines[$line]));
        $lines[$line] = $edited;
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /**
     * @param \Closure(): mixed $lookup
     */
    private function assertUnreliable(\Closure $lookup, string $reason): void
    {
        try {
            $lookup();
            Assert::fail('a damaged index served a lookup silently');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), $reason);
        }
    }

    /**
     * @return null|array{bytes:int, count:int}
     */
    private function head(string $index): array | null
    {
        $raw = (string)file_get_contents($this->derivedPath('.indexes.json'));
        $heads = json_decode($raw, true);
        $head = \is_array($heads)
            ? ($heads[$index . '.index.ndjson'] ?? null)
            : null;

        return \is_array($head)
            && \is_int($head['bytes'] ?? null)
            && \is_int($head['count'] ?? null)
            ? ['bytes' => $head['bytes'], 'count' => $head['count']]
            : null;
    }

    private function dropDerivedFiles(): void
    {
        foreach (['.offsets', '.indexes.json'] as $file) {
            unlink($this->derivedPath($file));
        }

        $path = StorageManifest::path($this->dbDir);
        $manifest = json_decode((string)file_get_contents($path), true);
        Assert::true(\is_array($manifest));
        $manifest['compat'] = [];
        file_put_contents($path, json_encode($manifest));
    }

    /**
     * A fresh instance over the same directory, as the next request gets.
     */
    private function reopen(): JsonDataProvider
    {
        (new \ReflectionProperty(JsonDataProvider::class, 'instances'))
            ->setValue(null, []);

        return JsonDataProvider::getInstance($this->dbDir);
    }

    private function indexPath(string $index): string
    {
        return $this->dbDir . '/' . self::TABLE . '/' . $index
            . '.index.ndjson';
    }

    private function derivedPath(
        string $file,
        string $table = self::TABLE,
    ): string {
        return $this->dbDir . '/' . StorageManifest::DIR . '/table.' . $table
            . $file;
    }

    /**
     * @param array<string,SortDirectionEnum> $fields
     */
    private static function index(string $name, array $fields): IndexSchema
    {
        $parts = [];

        foreach ($fields as $field => $direction) {
            $parts[] = new IndexFieldSchema($field, $direction);
        }

        return new IndexSchema($name, $parts);
    }

    /**
     * @return array{id:int, n:int, f:float, s:string}
     */
    private static function randomRow(int $id): array
    {
        return [
            'id' => $id,
            'n'  => mt_rand(0, 100),
            'f'  => mt_rand(0, 200) / 4,
            's'  => \chr(mt_rand(97, 122)) . (mt_rand(0, 1) === 1 ? 'z' : ''),
        ];
    }
}
