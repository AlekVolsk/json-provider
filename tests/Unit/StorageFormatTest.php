<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Services\Backup\BackupManifest;
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
 * The storage format manifest (.jdp/format.json) and what the engine does
 * with each state of it when it opens a database.
 *
 * A provider instance reads the manifest once, when it is constructed, and
 * is then cached per path for the life of the process. Tests that need a
 * fresh open therefore create the database in a child process (or reopen
 * a path whose previous open failed — a failed construction is not
 * cached), and tests about "every initialization" run each open in its own
 * child, as PHP-FPM does per request.
 */
final class StorageFormatTest
{
    /**
     * Public methods that never write to the database files; every other
     * public method must appear in writeCalls() and be refused in
     * read-only mode.
     */
    private const array READ_METHODS = [
        'setLocale',
        'resetLocale',
        'setComparisonMode',
        'setBrokenRecordPolicy',
        'setFkBackingPolicy',
        'registerDto',
        'unregisterDto',
        'table',
        'hasTable',
        'tableNames',
        'columnNames',
        'readAll',
        'getLastInsertedId',
        'getNextId',
        'getTableComment',
        'getColumnComment',
        'getColumnComments',
        'describeTable',
        'getTableSchema',
        'diffTable',
        'relations',
        'validateTable',
        'validate',
        'backup',
        'storageStatus',
        'select',
        'count',
        'invalidateCache',
        'flushDb',
    ];

    private string $root;

    private string $dbDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-storage-format');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    #[Test]
    public function newDatabaseIsCreatedInCurrentGeneration(): void
    {
        $logger = new SpyLogger();

        $notices = self::captureNotices(function () use ($logger): void {
            JsonDataProvider::createDatabase($this->dbDir, null, $logger);
        });

        Assert::same($this->manifestOnDisk(), [
            'generation' => StorageManifest::GENERATION,
            'compat'     => ['lineOffsets', 'sortedIndexHeads'],
            'roCompat'   => [],
            'incompat'   => [],
        ]);
        Assert::same($logger->records, []);
        Assert::same($notices, []);
    }

    /**
     * Each provider initialization over a generation-1 database warns — as
     * every PHP-FPM request would — and the warning leaves the database
     * as it was.
     */
    #[Test]
    public function legacyDatabaseWarnsOnEveryInitialization(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);

        foreach ([1, 2] as $run) {
            [$notices] = EngineProcess::current(
                $this->dbDir,
                [['op' => 'initNotices']],
            );

            Assert::true(\is_array($notices));
            Assert::count($notices, 1, 'run ' . $run);
            Assert::string($notices[0] ?? null)
                ->contains('deprecated|')
                ->contains('storage format generation 1')
                ->contains($this->dbDir);
        }

        Assert::false(is_dir($this->dbDir . '/' . StorageManifest::DIR));
    }

    #[Test]
    public function legacyWarningGoesToTheLoggerWhenGiven(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);
        $logger = new SpyLogger();

        $notices = self::captureNotices(function () use ($logger): void {
            JsonDataProvider::getInstance($this->dbDir, null, $logger);
        });

        Assert::same($notices, []);
        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['level'], 'warning');
        Assert::string($logger->records[0]['message'])
            ->contains('storage format generation 1');
    }

    /**
     * A generation-1 database keeps working exactly as under 1.0: the new
     * engine reads and writes it and adds nothing of its own format.
     */
    #[Test]
    public function legacyDatabaseKeepsWorkingTheOldWay(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);
        $db = JsonDataProvider::getInstance(
            $this->dbDir,
            null,
            new SpyLogger(),
        );

        $id = $db->table(CompatFixture::ITEMS)->insertByArray([
            'ownerId' => 1,
            'title'   => 'new',
            'qty'     => 9,
        ]);
        $db->table(CompatFixture::ITEMS)->updateByIdByArray($id, ['qty' => 10]);
        $db->table(CompatFixture::OWNERS)->deleteById(2);
        $db->rebuildAllIndexes(CompatFixture::ITEMS);
        $db->repair();

        Assert::same(
            array_column(
                $db->table(CompatFixture::ITEMS)->orderBy('id')
                    ->selectAllByArray(),
                'id',
            ),
            [1, 2, 5, 6, 7],
        );
        Assert::same($db->validate()->issues, []);
        Assert::false(is_dir($this->dbDir . '/' . StorageManifest::DIR));
        Assert::same(
            EngineProcess::legacy($this->dbDir, [['op' => 'validate']]),
            [[]],
        );
    }

    #[Test]
    public function pathWithoutDatabaseOpensSilently(): void
    {
        $logger = new SpyLogger();
        mkdir($this->dbDir, 0755, true);

        $notices = self::captureNotices(function () use ($logger): void {
            JsonDataProvider::getInstance($this->dbDir, null, $logger);
            JsonDataProvider::getInstance(
                $this->root . '/absent',
                null,
                $logger,
            );
        });

        Assert::same($notices, []);
        Assert::same($logger->records, []);
    }

    #[Test]
    public function newerGenerationIsRefused(): void
    {
        $this->createInChild();
        $this->writeManifest('{"generation": 3}');

        $e = $this->openFails();

        Assert::same(
            $e->error,
            JsonProviderErrorEn::StorageGenerationUnsupported,
        );
        Assert::string($e->getMessage())->contains('3')->contains('2');
    }

    #[Test]
    public function unknownIncompatFeatureIsRefused(): void
    {
        $this->createInChild();
        $this->writeManifest(
            '{"generation": 2, "incompat": ["future-layout"]}',
        );

        $e = $this->openFails();

        Assert::same($e->error, JsonProviderErrorEn::StorageFeatureUnsupported);
        Assert::string($e->getMessage())->contains('future-layout');
    }

    /**
     * A manifest that exists but cannot be trusted refuses the open rather
     * than guessing a generation: a guess could hide a feature this engine
     * must not ignore.
     */
    #[Test]
    public function corruptManifestIsRefused(): void
    {
        $this->createInChild();

        foreach (
            [
                '',
                'not json',
                '[]',
                '[2]',
                '{}',
                '"2"',
                '{"generation": "2"}',
                '{"generation": 2.0}',
                '{"generation": 1}',
                '{"generation": 0}',
                '{"generation": -2}',
                '{"generation": 2, "compat": "x"}',
                '{"generation": 2, "roCompat": {"a": "b"}}',
                '{"generation": 2, "incompat": [1]}',
                '{"generation": 2, "compat": [""]}',
                '{"generation": 2, "compat": null}',
            ] as $bytes
        ) {
            $this->writeManifest($bytes);
            $e = $this->openFails();

            Assert::same(
                $e->error,
                JsonProviderErrorEn::StorageManifestCorrupt,
                'manifest: ' . $bytes,
            );
            Assert::string($e->getMessage())->contains(
                StorageManifest::path($this->dbDir),
            );
        }
    }

    /**
     * A later generation may add keys and omit empty flag lists; this
     * engine reads such a manifest of its own generation.
     */
    #[Test]
    public function manifestToleratesUnknownKeysAndMissingLists(): void
    {
        $this->createInChild();
        $this->writeManifest('{"generation": 2, "writtenBy": {"v": "9"}}');
        $logger = new SpyLogger();

        $db = JsonDataProvider::getInstance($this->dbDir, null, $logger);
        $db->table(CompatFixture::ITEMS)->insertByArray([
            'ownerId' => 1,
            'title'   => 'x',
            'qty'     => 1,
        ]);

        Assert::same($logger->records, []);
        Assert::count($db->table(CompatFixture::ITEMS)->selectAllByArray(), 7);
    }

    #[Test]
    public function unknownCompatFeatureIsIgnored(): void
    {
        $this->createInChild();
        $this->writeManifest('{"generation": 2, "compat": ["future-hint"]}');
        $logger = new SpyLogger();

        $db = JsonDataProvider::getInstance($this->dbDir, null, $logger);
        $db->table(CompatFixture::ITEMS)->insertByArray([
            'ownerId' => 1,
            'title'   => 'x',
            'qty'     => 1,
        ]);

        Assert::same($logger->records, []);
        Assert::count($db->table(CompatFixture::ITEMS)->selectAllByArray(), 7);
    }

    /**
     * An unknown roCompat feature opens the database for reading only:
     * every read keeps working, every write is refused before it touches
     * a single file.
     */
    #[Test]
    public function unknownRoCompatFeatureOpensReadOnly(): void
    {
        $this->createInChild();
        $this->writeManifest(
            '{"generation": 2, "roCompat": ["future-journal"]}',
        );
        $logger = new SpyLogger();

        $db = JsonDataProvider::getInstance($this->dbDir, null, $logger);

        Assert::count($logger->records, 1);
        Assert::same($logger->records[0]['level'], 'warning');
        Assert::string($logger->records[0]['message'])
            ->contains('read-only')
            ->contains('future-journal');

        Assert::count($db->table(CompatFixture::ITEMS)->selectAllByArray(), 6);
        Assert::same($db->count(CompatFixture::ITEMS), 6);
        Assert::same($db->validate()->issues, []);
        $archive = $db->backup($this->root . '/read-only.tar.gz');
        Assert::true(is_file($archive));

        $before = $this->filesOf($this->dbDir);

        foreach ($this->writeCalls($db, $archive) as $method => $call) {
            try {
                $call();
                Assert::fail($method . ' wrote to a read-only database');
            } catch (JsonProviderServiceException $e) {
                Assert::same(
                    $e->error,
                    JsonProviderErrorEn::StorageReadOnly,
                    $method,
                );
                Assert::string($e->getMessage())->contains('future-journal');
            }

            Assert::same($this->filesOf($this->dbDir), $before, $method);
        }
    }

    #[Test]
    public function readOnlyWarningFallsBackToUserWarning(): void
    {
        $this->createInChild();
        $this->writeManifest(
            '{"generation": 2, "roCompat": ["future-journal"]}',
        );

        [$notices] = EngineProcess::current(
            $this->dbDir,
            [['op' => 'initNotices']],
        );

        Assert::true(\is_array($notices));
        Assert::count($notices, 1);
        Assert::string($notices[0] ?? null)
            ->contains('warning|')
            ->contains('future-journal');
    }

    /**
     * The archive field is informational: absent or malformed it reads as
     * null, and an archive that does not know its generation omits it.
     */
    #[Test]
    public function archiveStorageFormatFieldIsOptional(): void
    {
        $base = ['format' => BackupManifest::FORMAT, 'version' => 1];

        $invalid = [[], ['storageFormat' => '2'], ['storageFormat' => 0]];

        foreach ($invalid as $extra) {
            Assert::null(
                BackupManifest::fromArray($base + $extra)->storageFormat,
            );
        }

        Assert::same(
            BackupManifest::fromArray($base + ['storageFormat' => 2])
                ->storageFormat,
            2,
        );
        Assert::false(\array_key_exists(
            'storageFormat',
            (new BackupManifest('now', []))->toArray(),
        ));
    }

    /**
     * Keeps the read-only guard complete: a public method added later must
     * be classified here as a read or listed among the guarded writes.
     */
    #[Test]
    public function everyPublicMethodIsClassified(): void
    {
        $this->createInChild();
        $db = JsonDataProvider::getInstance($this->dbDir);
        $methods = [];

        foreach (
            (new \ReflectionClass(JsonDataProvider::class))
                ->getMethods(\ReflectionMethod::IS_PUBLIC) as $method
        ) {
            if (!$method->isStatic() && !$method->isConstructor()) {
                $methods[] = $method->getName();
            }
        }

        $writes = array_keys($this->writeCalls($db, ''));
        $classified = array_merge(self::READ_METHODS, $writes);
        sort($methods);
        sort($classified);

        Assert::same($classified, $methods);
        Assert::same(array_intersect(self::READ_METHODS, $writes), []);
    }

    /**
     * One call per public write method, with arguments a writable
     * database would accept or reject on its own merits — the read-only
     * refusal must come first either way.
     *
     * @return array<string,\Closure(): void>
     */
    private function writeCalls(JsonDataProvider $db, string $archive): array
    {
        $items = CompatFixture::ITEMS;
        $owners = CompatFixture::OWNERS;
        $qty = new IndexFieldSchema('qty', SortDirectionEnum::ASC);
        $item = ['ownerId' => 1, 'title' => 'x', 'qty' => 1];
        $order = ['id', 'qty', 'title', 'ownerId'];
        $relation = new RelationSchema(
            fromTable: $items,
            foreignKey: 'qty',
            toTable: $owners,
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
        );
        $unique = new UniqueConstraint('uq_owners_name', ['name']);
        $index = new IndexSchema('idx_qty', [$qty]);
        $composite = 'idx_items_owner_title';

        return [
            'createTable' => static function () use ($db): void {
                $db->createTable(CompatFixture::extraTable());
            },
            'dropTable' => static function () use ($db, $items): void {
                $db->dropTable($items);
            },
            'renameTable' => static function () use ($db, $items): void {
                $db->renameTable($items, 'renamed');
            },
            'migrateColumns' => static function () use ($db): void {
                $db->migrateColumns(CompatFixture::extraTable());
            },
            'insert' => static function () use ($db, $items, $item): void {
                $db->insert($items, $item);
            },
            'update' => static function () use ($db, $items): void {
                $db->update($items, [], ['qty' => 5]);
            },
            'delete' => static function () use ($db, $items): void {
                $db->delete($items, []);
            },
            'reorderColumns' => static function () use (
                $db,
                $items,
                $order,
            ): void {
                $db->reorderColumns($items, $order);
            },
            'truncate' => static function () use ($db, $items): void {
                $db->truncate($items);
            },
            'importRecords' => static function () use ($db, $items): void {
                $db->importRecords($items, []);
            },
            'renameColumn' => static function () use ($db, $items): void {
                $db->renameColumn($items, 'qty', 'amount');
            },
            'setTableComment' => static function () use ($db, $items): void {
                $db->setTableComment($items, 'note');
            },
            'setColumnComment' => static function () use ($db, $items): void {
                $db->setColumnComment($items, 'qty', 'note');
            },
            'setColumnComments' => static function () use ($db, $items): void {
                $db->setColumnComments($items, ['qty' => 'note']);
            },
            'rebuildIndex' => static function () use (
                $db,
                $items,
                $composite,
            ): void {
                $db->rebuildIndex($items, $composite);
            },
            'rebuildAllIndexes' => static function () use ($db, $items): void {
                $db->rebuildAllIndexes($items);
            },
            'addIndex' => static function () use ($db, $items, $index): void {
                $db->addIndex($items, $index);
            },
            'dropIndex' => static function () use (
                $db,
                $items,
                $composite,
            ): void {
                $db->dropIndex($items, $composite);
            },
            'addUniqueConstraint' => static function () use (
                $db,
                $owners,
                $unique,
            ): void {
                $db->addUniqueConstraint($owners, $unique);
            },
            'dropUniqueConstraint' => static function () use (
                $db,
                $owners,
            ): void {
                $db->dropUniqueConstraint($owners, 'uq_owners_email');
            },
            'addRelation' => static function () use ($db, $relation): void {
                $db->addRelation($relation);
            },
            'dropRelation' => static function () use (
                $db,
                $items,
                $owners,
            ): void {
                $db->dropRelation($items, 'ownerId', $owners);
            },
            'optimizeTable' => static function () use ($db, $items): void {
                $db->optimizeTable($items);
            },
            'repairTable' => static function () use ($db, $items): void {
                $db->repairTable($items);
            },
            'repair' => static function () use ($db): void {
                $db->repair();
            },
            'restore' => static function () use ($db, $archive): void {
                $db->restore($archive);
            },
            'migrateStorage' => static function () use ($db): void {
                $db->migrateStorage();
            },
        ];
    }

    private function createInChild(): void
    {
        EngineProcess::current($this->dbDir, [['op' => 'create']]);
    }

    private function writeManifest(string $bytes): void
    {
        file_put_contents(StorageManifest::path($this->dbDir), $bytes);
    }

    private function openFails(): JsonProviderException
    {
        try {
            JsonDataProvider::getInstance($this->dbDir, null, new SpyLogger());
        } catch (JsonProviderException $e) {
            return $e;
        }

        Assert::fail('the database opened');
    }

    /**
     * @return array<mixed>
     */
    private function manifestOnDisk(): array
    {
        $raw = file_get_contents(StorageManifest::path($this->dbDir));
        Assert::true(\is_string($raw));

        $decoded = json_decode($raw, true);
        Assert::true(\is_array($decoded));

        return $decoded;
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

    /**
     * Runs $fn and returns the PHP user notices it raised.
     *
     * @return list<string>
     */
    private static function captureNotices(\Closure $fn): array
    {
        $notices = [];

        set_error_handler(
            static function (
                int $level,
                string $message,
            ) use (&$notices): bool {
                $notices[] = $level . '|' . $message;

                return true;
            },
            E_USER_DEPRECATED | E_USER_WARNING,
        );

        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $notices;
    }
}
