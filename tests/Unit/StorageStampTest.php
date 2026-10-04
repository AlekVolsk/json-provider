<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderRelationException;
use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IntegrityReport;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\StorageManifest;
use AV\JsonProvider\Tests\Support\CompatFixture;
use AV\JsonProvider\Tests\Support\EngineProcess;
use AV\JsonProvider\Tests\Support\SpyLogger;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The generation-2 table stamp (dataIno in meta.json) and the gate built
 * on it.
 *
 * A full rewrite replaces the data file through a rename, then rebuilds
 * the indexes, then commits the counters. A process dying between the
 * rename and the commit leaves new data under old indexes; when the change
 * kept the file size (a foreign key 2 → 3), a size-only gate trusts those
 * indexes. The stamp records the inode of the file the last commit
 * described; the rename always changes it.
 */
final class StorageStampTest
{
    private const string OWNERS = 'owners';
    private const string ITEMS = 'items';

    private string $root;

    private string $dbDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-storage-stamp');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * The regression of the whole stamp: a same-size rewrite interrupted
     * before the index rebuild must not be served from the old indexes —
     * neither by a restrict probe, which would let a parent referenced
     * only by the rewritten row be deleted, nor by a query, which would
     * miss that row.
     */
    #[Test]
    public function interruptedSameSizeRewriteIsNotTrusted(): void
    {
        $db = $this->restrictDatabase(new SpyLogger());

        $this->replaceDataFile(
            self::ITEMS,
            static fn (string $raw): string => str_replace(
                '"title":"b1","ownerId":2',
                '"title":"b1","ownerId":4',
                $raw,
            ),
        );

        try {
            $db->table(self::OWNERS)->deleteById(4);
            Assert::fail('a referenced parent was deleted');
        } catch (JsonProviderRelationException $e) {
            Assert::same($e->error, JsonProviderErrorEn::ForeignKeyRestrict);
        }

        Assert::same(
            array_column(
                $db->table(self::ITEMS)->where('ownerId', '=', 4)
                    ->orderBy('id')->selectAllByArray(),
                'title',
            ),
            ['b1'],
        );

        Assert::same(self::lines($db->validate()), [
            'error|index_drift|' . self::ITEMS,
            'info|table_unverified|' . self::ITEMS . '|stale',
        ]);
    }

    /**
     * The next write heals a stale table with a full rewrite, which
     * rebuilds the indexes and stamps the new file.
     */
    #[Test]
    public function nextWriteHealsStaleTable(): void
    {
        $logger = new SpyLogger();
        $db = $this->restrictDatabase($logger);
        $this->replaceDataFile(
            self::ITEMS,
            static fn (string $raw): string => $raw,
        );

        $db->table(self::ITEMS)->insertByArray([
            'title'   => 'd1',
            'ownerId' => 1,
        ]);

        Assert::same($this->stamp(self::ITEMS), $this->inode(self::ITEMS));
        Assert::same($db->validate()->issues, []);
        Assert::count($logger->records, 1);
        Assert::string($logger->records[0]['message'])
            ->contains('"' . self::ITEMS . '"')
            ->contains('replaced after its last stamped commit');
    }

    #[Test]
    public function staleTableIsReportedOncePerInstance(): void
    {
        $logger = new SpyLogger();
        $db = $this->restrictDatabase($logger);
        $this->replaceDataFile(
            self::ITEMS,
            static fn (string $raw): string => $raw,
        );

        foreach ([1, 2, 3] as $owner) {
            $db->table(self::ITEMS)->where('ownerId', '=', $owner)
                ->selectAllByArray();
        }

        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['level'], 'warning');
    }

    /**
     * Every commit that rewrites the data file records the inode of the
     * new file; an append keeps the file and with it the stamp.
     */
    #[Test]
    public function everyRewriteStampsTheNewFile(): void
    {
        $db = $this->restrictDatabase(new SpyLogger());
        $items = $db->table(self::ITEMS);
        $archive = $db->backup($this->root . '/stamp.tar.gz');

        $steps = [
            'createTable' => static function (): void {},
            'insert'      => static function () use ($items): void {
                $items->insertByArray(['title' => 'x', 'ownerId' => 1]);
            },
            'update' => static function () use ($items): void {
                $items->updateByIdByArray(1, ['title' => 'renamed']);
            },
            'delete' => static function () use ($items): void {
                $items->deleteById(2);
            },
            'fkCascade' => function () use ($db): void {
                $this->cascadeDelete($db);
            },
            'restore' => static function () use ($db, $archive): void {
                $db->restore($archive);
            },
            'reorder' => static function () use ($db): void {
                $db->reorderColumns(self::ITEMS, ['id', 'ownerId', 'title']);
            },
            'rename' => static function () use ($db): void {
                $db->renameColumn(self::ITEMS, 'title', 'name');
            },
            'optimize' => static function () use ($db): void {
                $db->optimizeTable(self::ITEMS);
            },
            'import' => static function () use ($db): void {
                $db->importRecords(
                    self::ITEMS,
                    [['id' => 1, 'ownerId' => 1, 'name' => 'imported']],
                );
            },
            'truncate' => static function () use ($db): void {
                $db->truncate(self::ITEMS);
            },
        ];

        foreach ($steps as $step => $run) {
            $run();

            foreach ([self::OWNERS, self::ITEMS] as $table) {
                Assert::same(
                    $this->stamp($table),
                    $this->inode($table),
                    $step . ': ' . $table,
                );
            }

            Assert::same($db->validate()->issues, [], $step);
        }
    }

    #[Test]
    public function renamedTableKeepsItsStamp(): void
    {
        $logger = new SpyLogger();
        $db = $this->restrictDatabase($logger);
        $db->dropRelation(self::ITEMS, 'ownerId', self::OWNERS);
        $db->renameTable(self::ITEMS, 'goods');

        Assert::same($this->stamp('goods'), $this->inode('goods'));
        Assert::same(
            array_column(
                $db->table('goods')->where('ownerId', '=', 2)
                    ->selectAllByArray(),
                'title',
            ),
            ['b1'],
        );
        Assert::same($db->validate()->issues, []);
        Assert::same($logger->records, []);
    }

    /**
     * A table without a stamp (an older engine created it) is trusted by
     * size, as under 1.0, reported once, listed by validate and stamped by
     * repair.
     */
    #[Test]
    public function unstampedTableIsTrustedBySizeUntilRepair(): void
    {
        $this->restrictDatabaseElsewhere();
        $this->dropStamp(self::ITEMS);
        $logger = new SpyLogger();
        $db = JsonDataProvider::getInstance($this->dbDir, null, $logger);

        foreach ([1, 2] as $owner) {
            Assert::count(
                $db->table(self::ITEMS)->where('ownerId', '=', $owner)
                    ->selectAllByArray(),
                1,
            );
        }

        Assert::count($logger->records, 1);
        Assert::string($logger->records[0]['message'])
            ->contains('carries no generation-2 stamp');
        Assert::same(self::lines($db->validate()), [
            'info|table_unverified|' . self::ITEMS . '|unstamped',
        ]);

        $report = $db->repair();

        Assert::true(
            \in_array(
                'info|table_unverified|' . self::ITEMS . '|unstamped',
                self::lines($report),
                true,
            ),
        );
        Assert::same($this->stamp(self::ITEMS), $this->inode(self::ITEMS));
        Assert::same($db->validate()->issues, []);
    }

    /**
     * An append does not prove the indexes of an unstamped table, so it
     * leaves the table unstamped.
     */
    #[Test]
    public function appendDoesNotStampUnstampedTable(): void
    {
        $this->restrictDatabaseElsewhere();
        $this->dropStamp(self::ITEMS);
        $db = JsonDataProvider::getInstance(
            $this->dbDir,
            null,
            new SpyLogger(),
        );

        $db->table(self::ITEMS)->insertByArray([
            'title'   => 'd1',
            'ownerId' => 1,
        ]);

        Assert::null($this->stamp(self::ITEMS));
        Assert::same(self::lines($db->validate()), [
            'info|table_unverified|' . self::ITEMS . '|unstamped',
        ]);
    }

    /**
     * Only a rewrite commit (indexes rebuilt from the same records) writes
     * the stamp; an append commit and a counters-only commit (a repair
     * trimming a torn tail, indexes untouched) leave it as it was — and
     * none of them writes one while stamps are off.
     */
    #[Test]
    public function onlyRewriteCommitWritesTheStamp(): void
    {
        JsonDataProvider::createDatabase($this->dbDir)->createTable(
            TableSchema::create(
                name: self::OWNERS,
                columns: ['name' => ColumnTypes::STRING],
            ),
        );
        $this->dropStamp(self::OWNERS);
        $meta = new MetaRegistry(new JsonStorage($this->dbDir));

        $meta->commitRewrite(self::OWNERS, 1, 10);
        $meta->commitAppend(self::OWNERS, 2, 20);
        $meta->commitCounters(self::OWNERS, 3, 30);
        Assert::null($meta->getDataIno(self::OWNERS));

        $meta->enableDataStamps(static fn (string $table): int => 4242);
        $meta->commitAppend(self::OWNERS, 4, 40);
        $meta->commitCounters(self::OWNERS, 5, 50);
        Assert::null($meta->getDataIno(self::OWNERS));

        $meta->commitRewrite(self::OWNERS, 6, 60);
        Assert::same($meta->getDataIno(self::OWNERS), 4242);

        $meta->enableDataStamps(static fn (string $table): int => 7);
        $meta->commitAppend(self::OWNERS, 7, 70);
        $meta->commitCounters(self::OWNERS, 8, 80);
        Assert::same($meta->getDataIno(self::OWNERS), 4242);
        Assert::same($meta->getLineCount(self::OWNERS), 8);
        Assert::same($meta->getByteSize(self::OWNERS), 80);
    }

    /**
     * A stamp that is not an integer is a corrupt meta entry, like any
     * other counter of the wrong type.
     */
    #[Test]
    public function nonIntegerStampIsCorruptMeta(): void
    {
        JsonDataProvider::createDatabase($this->dbDir)->createTable(
            TableSchema::create(
                name: self::OWNERS,
                columns: ['name' => ColumnTypes::STRING],
            ),
        );
        $path = $this->dbDir . '/meta.json';
        $raw = (string)file_get_contents($path);
        file_put_contents(
            $path,
            (string)preg_replace('/"dataIno": \d+/', '"dataIno": "7"', $raw),
        );
        $meta = new MetaRegistry(new JsonStorage($this->dbDir));

        try {
            $meta->getDataIno(self::OWNERS);
            Assert::fail('a string stamp was accepted');
        } catch (JsonProviderServiceException $e) {
            Assert::same($e->error, JsonProviderErrorEn::MetaCounterNotInt);
        }
    }

    /**
     * Repair refuses to certify the indexes of a table whose data file
     * holds a line that is not a record: they would silently skip it.
     */
    #[Test]
    public function repairRefusesToStampBrokenFile(): void
    {
        $db = $this->restrictDatabase(new SpyLogger());
        $this->replaceDataFile(
            self::ITEMS,
            static fn (string $raw): string => str_replace(
                '"title":"b1"',
                '"title"!"b1"',
                $raw,
            ),
        );

        $unverified = null;

        foreach ($db->repair()->issues as $issue) {
            if ($issue->category->value === 'table_unverified') {
                $unverified = $issue;
            }
        }

        Assert::notNull($unverified);
        Assert::false($unverified->repaired);
        Assert::string((string)$unverified->repairError)
            ->contains('not records');
        Assert::notSame($this->stamp(self::ITEMS), $this->inode(self::ITEMS));
    }

    /**
     * A generation-1 database works exactly as under 1.0: no stamp is
     * written, the size alone decides, nothing is reported per table.
     */
    #[Test]
    public function legacyDatabaseIsNeverStamped(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);
        $logger = new SpyLogger();
        $db = JsonDataProvider::getInstance($this->dbDir, null, $logger);

        $db->table(CompatFixture::ITEMS)->updateByIdByArray(1, ['qty' => 5]);
        $db->table(CompatFixture::ITEMS)->insertByArray([
            'ownerId' => 1,
            'title'   => 'x',
            'qty'     => 1,
        ]);
        $db->createTable(CompatFixture::extraTable());
        $db->repair();

        $tables = [
            CompatFixture::OWNERS,
            CompatFixture::ITEMS,
            CompatFixture::EXTRAS,
        ];

        foreach ($tables as $table) {
            Assert::null($this->stamp($table), $table);
        }

        Assert::false(is_dir($this->dbDir . '/' . StorageManifest::DIR));
        Assert::count($logger->records, 1);
        Assert::same($db->validate()->issues, []);
    }

    private function restrictDatabase(SpyLogger $logger): JsonDataProvider
    {
        $db = JsonDataProvider::createDatabase($this->dbDir, null, $logger);
        self::buildRestrictSchema($db);

        return $db;
    }

    /**
     * Builds the database under another path and moves it into place, so
     * the first getInstance() of $dbDir opens it fresh — after the test
     * edited its files.
     */
    private function restrictDatabaseElsewhere(): void
    {
        $db = JsonDataProvider::createDatabase($this->root . '/seed');
        self::buildRestrictSchema($db);
        rename($this->root . '/seed', $this->dbDir);
    }

    private static function buildRestrictSchema(JsonDataProvider $db): void
    {
        $db->createTable(TableSchema::create(
            name: self::OWNERS,
            columns: ['name' => ColumnTypes::STRING],
        ));
        $db->createTable(TableSchema::create(
            name: self::ITEMS,
            columns: [
                'title'   => ColumnTypes::STRING,
                'ownerId' => ColumnTypes::INT,
            ],
            indexes: [
                new IndexSchema(
                    'idx_items_owner',
                    [new IndexFieldSchema('ownerId', SortDirectionEnum::ASC)],
                ),
            ],
        ));
        $db->addRelation(new RelationSchema(
            fromTable: self::ITEMS,
            foreignKey: 'ownerId',
            toTable: self::OWNERS,
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::RESTRICT,
        ));

        foreach (['a', 'b', 'c'] as $n => $name) {
            $db->table(self::OWNERS)->insertByArray(['name' => $name]);
            $db->table(self::ITEMS)->insertByArray([
                'title'   => $name . '1',
                'ownerId' => $n + 1,
            ]);
        }

        $db->table(self::OWNERS)->insertByArray(['name' => 'd']);
    }

    /**
     * Deletes owner 3 after detaching its item — a write that goes
     * through the FK engine's multi-table commit.
     */
    private function cascadeDelete(JsonDataProvider $db): void
    {
        $db->table(self::ITEMS)->where('ownerId', '=', 3)
            ->updateByArray(['ownerId' => 1]);
        $db->table(self::OWNERS)->deleteById(3);
    }

    /**
     * Replaces the table's data file the way a full rewrite does — a temp
     * sibling renamed over it — without touching indexes or meta: the
     * state a rewrite leaves when it dies right after its rename.
     *
     * @param \Closure(string): string $edit
     */
    private function replaceDataFile(string $table, \Closure $edit): void
    {
        $path = $this->dataFile($table);
        $raw = (string)file_get_contents($path);
        $edited = $edit($raw);

        Assert::same(\strlen($edited), \strlen($raw));

        file_put_contents($path . '.crash.tmp', $edited);
        rename($path . '.crash.tmp', $path);
    }

    private function dropStamp(string $table): void
    {
        $path = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($path), true);
        Assert::true(\is_array($meta) && \is_array($meta[$table] ?? null));

        unset($meta[$table]['dataIno']);
        file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT));
    }

    private function stamp(string $table): int | null
    {
        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        $entry = \is_array($meta) ? ($meta[$table] ?? null) : null;
        $ino = \is_array($entry) ? ($entry['dataIno'] ?? null) : null;

        return \is_int($ino) ? $ino : null;
    }

    private function inode(string $table): int
    {
        clearstatcache();

        return (int)fileinode($this->dataFile($table));
    }

    private function dataFile(string $table): string
    {
        return $this->dbDir . '/' . $table . '/'
            . TableSchema::dataFileName($table);
    }

    /**
     * @return list<string>
     */
    private static function lines(IntegrityReport $report): array
    {
        $lines = [];

        foreach ($report->issues as $issue) {
            $line = $issue->severity->value . '|' . $issue->category->value
                . '|' . ($issue->tableName ?? '');
            $freshness = $issue->context['freshness'] ?? null;

            $lines[] = \is_string($freshness)
                ? $line . '|' . $freshness
                : $line;
        }

        return $lines;
    }
}
