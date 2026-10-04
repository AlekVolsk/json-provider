<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
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
 * storageStatus() and migrateStorage(): the explicit way from one storage
 * format generation to the next, and the restore path that ends with the
 * same migration.
 */
final class StorageMigrationTest
{
    private string $root;

    private string $dbDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-storage-migration');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * The status of a 1.0 database lists the step and every table, and
     * reading it changes nothing on disk.
     */
    #[Test]
    public function statusOfLegacyDatabase(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);
        $before = $this->filesOf($this->dbDir);
        $db = JsonDataProvider::getInstance(
            $this->dbDir,
            null,
            new SpyLogger(),
        );

        $status = $db->storageStatus();

        Assert::same($status->generation, 1);
        Assert::same($status->engineGeneration, StorageManifest::GENERATION);
        Assert::same($status->pendingSteps, ['1->2']);
        Assert::same(
            $status->pendingTables,
            [CompatFixture::OWNERS, CompatFixture::ITEMS],
        );
        Assert::same([$status->compat, $status->roCompat], [[], []]);
        Assert::false($status->readOnly);
        Assert::false($status->isCurrent());
        Assert::same($this->filesOf($this->dbDir), $before);
    }

    /**
     * Migrating a 1.0 database: every table rebuilt and stamped, the
     * manifest written, no per-table notice raised on the way, the data
     * untouched — and the 1.0 engine keeps working on the result.
     */
    #[Test]
    public function migratesLegacyDatabase(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);
        $logger = new SpyLogger();
        $db = JsonDataProvider::getInstance($this->dbDir, null, $logger);
        $rows = $this->rows($db);

        $report = $db->migrateStorage();

        Assert::same($report->fromGeneration, 1);
        Assert::same($report->toGeneration, 2);
        Assert::same($report->steps, ['1->2']);
        Assert::same(
            $report->refreshedTables,
            [CompatFixture::OWNERS, CompatFixture::ITEMS],
        );
        Assert::same($report->skippedTables, []);
        Assert::same(
            StorageManifest::read($this->dbDir)->generation,
            StorageManifest::GENERATION,
        );

        foreach ([CompatFixture::OWNERS, CompatFixture::ITEMS] as $table) {
            Assert::same($this->stamp($table), $this->inode($table), $table);
        }

        Assert::true($db->storageStatus()->isCurrent());
        Assert::same($this->rows($db), $rows);
        Assert::same($db->validate()->issues, []);
        Assert::count($logger->records, 1);
        Assert::same(
            EngineProcess::current($this->dbDir, [['op' => 'initNotices']]),
            [[]],
        );

        [, $issues] = EngineProcess::legacy($this->dbDir, [
            ['op' => 'insertMany', 'count' => 3, 'tag' => 'legacy'],
            ['op' => 'validate'],
        ]);

        Assert::same($issues, []);
        Assert::same($db->validate()->issues, []);
    }

    #[Test]
    public function migrationIsIdempotent(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);
        $db = JsonDataProvider::getInstance(
            $this->dbDir,
            null,
            new SpyLogger(),
        );
        $db->migrateStorage();
        $after = $this->filesOf($this->dbDir);

        $again = $db->migrateStorage();

        Assert::same($again->fromGeneration, 2);
        Assert::same($again->toGeneration, 2);
        Assert::same($again->steps, []);
        Assert::same($again->refreshedTables, []);
        Assert::same($this->filesOf($this->dbDir), $after);
    }

    /**
     * Step 1 -> 2 never takes a 1.0 index on faith: an index that drifted
     * from its data is rebuilt, not stamped as it is.
     */
    #[Test]
    public function migrationRebuildsIndexesInsteadOfTrustingThem(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);
        $this->swapIndexKeys(CompatFixture::ITEMS, 'idx_items_owner_title');
        $db = JsonDataProvider::getInstance(
            $this->dbDir,
            null,
            new SpyLogger(),
        );

        Assert::true(
            \in_array(
                'index_drift',
                $this->categories($db),
                true,
            ),
        );

        $db->migrateStorage();

        Assert::same($db->validate()->issues, []);
    }

    /**
     * In a generation-2 database the migration brings every kind of
     * non-fresh table current: a stale stamp and a missing one by an
     * index rebuild, drifted counters (an insert that died before its
     * commit) by the canonical rewrite, which keeps the complete record.
     */
    #[Test]
    public function migrationBringsEveryTableCurrent(): void
    {
        $db = $this->currentDatabase();
        $db->createTable(CompatFixture::extraTable());
        $db->table(CompatFixture::EXTRAS)->insertByArray(['label' => 'a']);
        $committed = $this->metaEntry(CompatFixture::EXTRAS);
        $db->table(CompatFixture::EXTRAS)->insertByArray(['label' => 'late']);
        $this->setCounters(CompatFixture::EXTRAS, $committed);

        $this->replaceDataFile(CompatFixture::ITEMS, null);
        $this->dropStamp(CompatFixture::OWNERS);

        Assert::same($db->storageStatus()->pendingTables, [
            CompatFixture::OWNERS,
            CompatFixture::ITEMS,
            CompatFixture::EXTRAS,
        ]);

        $report = $db->migrateStorage();

        Assert::same($report->steps, []);
        Assert::same($report->refreshedTables, [
            CompatFixture::OWNERS,
            CompatFixture::ITEMS,
            CompatFixture::EXTRAS,
        ]);
        Assert::same(
            array_column(
                $db->table(CompatFixture::EXTRAS)->orderBy('id')
                    ->selectAllByArray(),
                'label',
            ),
            ['a', 'late'],
        );
        Assert::true($db->storageStatus()->isCurrent());
        Assert::same($db->validate()->issues, []);
    }

    /**
     * A table holding a line that is not a record is left alone and
     * reported, whether its stamp went stale (same size) or its counters
     * drifted (another size): a rewrite would drop the line, indexes built
     * around it cannot be certified. The line survives byte for byte.
     */
    #[Test]
    public function migrationLeavesTablesWithBrokenRecordAlone(): void
    {
        $db = $this->currentDatabase();
        $this->replaceDataFile(CompatFixture::ITEMS, '"title"!"bob-item-1"');
        $this->replaceOwnerName('"name":"ann"', '"name"!"an"');
        $items = file_get_contents($this->dataFile(CompatFixture::ITEMS));
        $owners = file_get_contents($this->dataFile(CompatFixture::OWNERS));

        $report = $db->migrateStorage();

        Assert::same($report->refreshedTables, []);
        Assert::same(
            $report->skippedTables,
            [CompatFixture::OWNERS, CompatFixture::ITEMS],
        );
        Assert::same(
            file_get_contents($this->dataFile(CompatFixture::ITEMS)),
            $items,
        );
        Assert::same(
            file_get_contents($this->dataFile(CompatFixture::OWNERS)),
            $owners,
        );
        Assert::same(
            $db->storageStatus()->pendingTables,
            [CompatFixture::OWNERS, CompatFixture::ITEMS],
        );
    }

    /**
     * A torn unterminated tail — what a crashed append leaves, never
     * acknowledged — does not hold the migration back: the heal drops it.
     */
    #[Test]
    public function tornTailDoesNotBlockMigration(): void
    {
        $db = $this->currentDatabase();
        file_put_contents(
            $this->dataFile(CompatFixture::ITEMS),
            '{"id":7,"ownerId":1,"tit',
            FILE_APPEND,
        );

        $report = $db->migrateStorage();

        Assert::same($report->refreshedTables, [CompatFixture::ITEMS]);
        Assert::same($report->skippedTables, []);
        Assert::count($db->table(CompatFixture::ITEMS)->selectAllByArray(), 6);
        Assert::same($db->validate()->issues, []);
    }

    #[Test]
    public function invalidTargetsAreRefused(): void
    {
        $db = $this->currentDatabase();

        foreach ([1, 3, 0] as $target) {
            try {
                $db->migrateStorage($target);
                Assert::fail('target ' . $target . ' was accepted');
            } catch (JsonProviderServiceException $e) {
                Assert::same(
                    $e->error,
                    JsonProviderErrorEn::StorageMigrationTargetInvalid,
                );
            }
        }
    }

    /**
     * Target 1 on a 1.0 database is a no-op that writes nothing of the
     * new format.
     */
    #[Test]
    public function migrationToCurrentLegacyGenerationIsNoOp(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);
        $db = JsonDataProvider::getInstance(
            $this->dbDir,
            null,
            new SpyLogger(),
        );

        $report = $db->migrateStorage(1);

        Assert::same([$report->fromGeneration, $report->toGeneration], [1, 1]);
        Assert::same($report->steps, []);
        Assert::same($report->refreshedTables, []);
        Assert::false(is_dir($this->dbDir . '/' . StorageManifest::DIR));
    }

    /**
     * A crash inside step 1 -> 2 leaves stamps without the manifest; the
     * re-run keeps the stamps that still match and finishes the step.
     */
    #[Test]
    public function interruptedMigrationIsFinishedByRerun(): void
    {
        EngineProcess::current($this->dbDir, [['op' => 'create']]);
        unlink(StorageManifest::path($this->dbDir));
        $this->dropStamp(CompatFixture::ITEMS);
        $db = JsonDataProvider::getInstance(
            $this->dbDir,
            null,
            new SpyLogger(),
        );

        Assert::same($db->storageStatus()->generation, 1);

        $report = $db->migrateStorage();

        Assert::same($report->steps, ['1->2']);
        Assert::same($report->refreshedTables, [CompatFixture::ITEMS]);
        Assert::true($db->storageStatus()->isCurrent());
        Assert::same($db->validate()->issues, []);
    }

    /**
     * Restore ends with the same migration: an archive of either version
     * restored on the current engine leaves a current database —
     * including a 1.0 database restored in place.
     */
    #[Test]
    public function restoreLeavesCurrentDatabase(): void
    {
        $archive = $this->root . '/legacy.tar.gz';
        EngineProcess::legacy($this->dbDir, [
            ['op' => 'create'],
            ['op' => 'backup', 'dest' => $archive],
            ['op' => 'delete', 'table' => CompatFixture::ITEMS, 'id' => 1],
        ]);
        $db = JsonDataProvider::getInstance(
            $this->dbDir,
            null,
            new SpyLogger(),
        );

        $db->restore($archive);

        Assert::same(
            StorageManifest::read($this->dbDir)->generation,
            StorageManifest::GENERATION,
        );
        Assert::true($db->storageStatus()->isCurrent());
        Assert::count($db->table(CompatFixture::ITEMS)->selectAllByArray(), 6);
        Assert::same($db->validate()->issues, []);
    }

    /**
     * The status reads the disk, not this instance's memory: an instance
     * that opened the database in generation 1 sees the migration another
     * process ran.
     */
    #[Test]
    public function statusFollowsMigrationByAnotherProcess(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);
        $db = JsonDataProvider::getInstance(
            $this->dbDir,
            null,
            new SpyLogger(),
        );

        Assert::same(
            EngineProcess::current($this->dbDir, [['op' => 'migrate']]),
            [['1->2']],
        );

        $status = $db->storageStatus();

        Assert::same($status->generation, 2);
        Assert::true($status->isCurrent());
    }

    private function currentDatabase(): JsonDataProvider
    {
        $db = JsonDataProvider::createDatabase(
            $this->dbDir,
            null,
            new SpyLogger(),
        );
        CompatFixture::build($db);
        CompatFixture::seed($db);

        return $db;
    }

    /**
     * @return list<array<string,null|scalar>>
     */
    private function rows(JsonDataProvider $db): array
    {
        $rows = [];

        foreach ([CompatFixture::OWNERS, CompatFixture::ITEMS] as $table) {
            foreach (
                $db->table($table)->orderBy('id')->selectAllByArray() as $row
            ) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function categories(JsonDataProvider $db): array
    {
        $categories = [];

        foreach ($db->validate()->issues as $issue) {
            $categories[] = $issue->category->value;
        }

        return $categories;
    }

    /**
     * Swaps the keys of the first two entries of an index file — the
     * structure stays valid, the content no longer matches the data.
     */
    private function swapIndexKeys(string $table, string $index): void
    {
        $path = $this->dbDir . '/' . $table . '/' . $index . '.index.ndjson';
        $lines = explode("\n", rtrim((string)file_get_contents($path), "\n"));
        $first = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);
        Assert::true(\is_array($first) && \is_array($second));

        [$first['line'], $second['line']] = [$second['line'], $first['line']];
        $lines[0] = (string)json_encode($first);
        $lines[1] = (string)json_encode($second);
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /**
     * Replaces the data file through a rename — a new inode — optionally
     * breaking the first occurrence of the item title "bob-item-1" into a
     * line that is not JSON.
     */
    private function replaceDataFile(string $table, string | null $broken): void
    {
        $path = $this->dataFile($table);
        $raw = (string)file_get_contents($path);

        if ($broken !== null) {
            $raw = str_replace('"title":"bob-item-1"', $broken, $raw);
        }

        file_put_contents($path . '.swap.tmp', $raw);
        rename($path . '.swap.tmp', $path);
    }

    /**
     * @return array<mixed>
     */
    private function metaEntry(string $table): array
    {
        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        Assert::true(\is_array($meta) && \is_array($meta[$table] ?? null));

        return $meta[$table];
    }

    /**
     * Puts the committed lineCount and byteSize of $entry back — the meta
     * an insert leaves when it dies after its append, before its commit.
     *
     * @param array<mixed> $entry
     */
    private function setCounters(string $table, array $entry): void
    {
        $path = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($path), true);
        Assert::true(\is_array($meta) && \is_array($meta[$table] ?? null));

        $meta[$table]['lineCount'] = $entry['lineCount'];
        $meta[$table]['byteSize'] = $entry['byteSize'];
        file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT));
    }

    /**
     * Rewrites the owners data file in place with one owner line broken
     * and one byte shorter: the committed byteSize no longer matches.
     */
    private function replaceOwnerName(string $from, string $to): void
    {
        $path = $this->dataFile(CompatFixture::OWNERS);
        $raw = (string)file_get_contents($path);
        file_put_contents($path, str_replace($from, $to, $raw));
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
     * Every file of the database except the lock files, by relative path,
     * with its content hash.
     *
     * @return array<string,string>
     */
    private function filesOf(string $dir): array
    {
        $files = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $relative = substr($entry->getPathname(), \strlen($dir) + 1);

            if ($entry->isFile() && !str_starts_with($relative, '.locks/')) {
                $files[$relative] = (string)md5_file($entry->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
