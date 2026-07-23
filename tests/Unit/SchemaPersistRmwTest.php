<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\JsonStorage;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for schema persist read-modify-write: mutate() works on the fresh
 * on-disk state (a stale in-memory registry can no longer wipe another
 * writer's changes), updateTable() transforms under the sidecar lock, and
 * read paths revalidate the cache via file stat.
 */
final class SchemaPersistRmwTest
{
    private const string DB_PATH = '/tmp/jp-schema-rmw-tests';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: 'main',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'title' => 'string'],
            indexes: [],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    // -- stale memory must not clobber foreign changes ---------------------

    #[Test]
    public function externalEditSurvivesSetTableComment(): void
    {
        // Load the schema into memory, then edit the file externally: add a
        // column to another (externally created) table and a relation.
        Assert::true($this->db->hasTable('main'));

        $raw = file_get_contents($this->schemaPath());
        \assert($raw !== false);
        $data = json_decode($raw, true);
        \assert(\is_array($data) && \is_array($data['tables']));

        $data['tables']['external'] = [
            'columns' => ['id' => 'int', 'extra' => 'string'],
            'unique'  => [],
            'indexes' => [[
                'name'      => 'pk',
                'fields'    => [['field' => 'id', 'direction' => 'asc']],
                'isPrimary' => true,
            ]],
        ];
        $data['relations'] = [[
            'from'       => 'external',
            'foreignKey' => 'id',
            'to'         => 'main',
            'references' => 'id',
            'type'       => 'belongsTo',
        ]];
        file_put_contents(
            $this->schemaPath(),
            json_encode($data, JSON_PRETTY_PRINT),
        );

        // A schema mutation from the (memory-stale) provider must merge with
        // the fresh file, not overwrite it.
        $this->db->setTableComment('main', 'commented');

        $registry = new SchemaRegistry(new JsonStorage($this->dbDir));
        Assert::true($registry->hasTable('external'));
        Assert::same(
            array_keys($registry->getTable('external')->columns),
            ['id', 'extra'],
        );
        Assert::count($registry->getRelations('main'), 1);
        Assert::same($registry->getTable('main')->tableComment, 'commented');
    }

    #[Test]
    public function twoRegistriesRegisterDifferentTablesBothSurvive(): void
    {
        $registryA = new SchemaRegistry(new JsonStorage($this->dbDir));
        $registryB = new SchemaRegistry(new JsonStorage($this->dbDir));

        // Warm both caches first, so each holds a copy without the other's
        // future table.
        Assert::true($registryA->hasTable('main'));
        Assert::true($registryB->hasTable('main'));

        $registryA->registerTable(TableSchema::create(
            name: 'alpha',
            uniqueConstraints: [],
            columns: ['id' => 'int'],
            indexes: [],
        ));
        $registryB->registerTable(TableSchema::create(
            name: 'beta',
            uniqueConstraints: [],
            columns: ['id' => 'int'],
            indexes: [],
        ));

        $fresh = new SchemaRegistry(new JsonStorage($this->dbDir));
        Assert::true($fresh->hasTable('alpha'));
        Assert::true($fresh->hasTable('beta'));
        Assert::true($fresh->hasTable('main'));
    }

    #[Test]
    public function registerRaceOnSameNameThrowsTableAlreadyExists(): void
    {
        $registryA = new SchemaRegistry(new JsonStorage($this->dbDir));
        $registryB = new SchemaRegistry(new JsonStorage($this->dbDir));

        Assert::true($registryA->hasTable('main'));
        Assert::true($registryB->hasTable('main'));

        $schema = TableSchema::create(
            name: 'contested',
            uniqueConstraints: [],
            columns: ['id' => 'int'],
            indexes: [],
        );

        $registryA->registerTable($schema);

        $caught = null;

        try {
            $registryB->registerTable($schema);
        } catch (StorageException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getErrorKey(), 'TABLE_ALREADY_EXISTS');
    }

    #[Test]
    public function crossProcessRegistrationsBothSurvive(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $registry = new \AV\JsonProvider\Registry\SchemaRegistry(
                new \AV\JsonProvider\Storage\JsonStorage($argv[2]),
            );
            $registry->registerTable(
                \AV\JsonProvider\Schema\TableSchema::create(
                    name: 'from_child',
                    uniqueConstraints: [],
                    columns: ['id' => 'int'],
                    indexes: [],
                ),
            );
            echo "done\n";
            PHP;

        $pipes = [];
        $proc = proc_open(
            [
                PHP_BINARY,
                '-r',
                $code,
                '--',
                \dirname(__DIR__, 2) . '/vendor/autoload.php',
                $this->dbDir,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        \assert(\is_resource($proc));
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        Assert::string((string)$stdout)->contains('done');

        $registry = new SchemaRegistry(new JsonStorage($this->dbDir));
        $registry->registerTable(TableSchema::create(
            name: 'from_parent',
            uniqueConstraints: [],
            columns: ['id' => 'int'],
            indexes: [],
        ));

        $fresh = new SchemaRegistry(new JsonStorage($this->dbDir));
        Assert::true($fresh->hasTable('from_child'));
        Assert::true($fresh->hasTable('from_parent'));
    }

    // -- stat-driven cache revalidation ------------------------------------

    #[Test]
    public function readPathsPickUpExternalChangesWithoutReload(): void
    {
        $registry = new SchemaRegistry(new JsonStorage($this->dbDir));
        Assert::false($registry->hasTable('appeared'));

        // Another registry (fresh handle set, same path) adds a table.
        $other = new SchemaRegistry(new JsonStorage($this->dbDir));
        $other->registerTable(TableSchema::create(
            name: 'appeared',
            uniqueConstraints: [],
            columns: ['id' => 'int'],
            indexes: [],
        ));

        // No reload() — the stat check must invalidate the cached copy.
        Assert::true($registry->hasTable('appeared'));
    }

    // -- updateTable -------------------------------------------------------

    #[Test]
    public function updateTableTransformsAndReturnsPersistedSchema(): void
    {
        $registry = new SchemaRegistry(new JsonStorage($this->dbDir));

        $updated = $registry->updateTable(
            'main',
            static fn (TableSchema $t): TableSchema => $t
                ->withTableComment('via updateTable'),
        );

        Assert::same($updated->tableComment, 'via updateTable');

        $fresh = new SchemaRegistry(new JsonStorage($this->dbDir));
        Assert::same(
            $fresh->getTable('main')->tableComment,
            'via updateTable',
        );
    }

    #[Test]
    public function updateTableUnknownTableThrows(): void
    {
        $registry = new SchemaRegistry(new JsonStorage($this->dbDir));

        $caught = null;

        try {
            $registry->updateTable(
                'ghost',
                static fn (TableSchema $t): TableSchema => $t,
            );
        } catch (StorageException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getErrorKey(), 'TABLE_NOT_FOUND');
    }

    #[Test]
    public function updateTableTransformExceptionLeavesFileUnchanged(): void
    {
        $before = file_get_contents($this->schemaPath());
        $registry = new SchemaRegistry(new JsonStorage($this->dbDir));

        $caught = null;

        try {
            $registry->updateTable(
                'main',
                static function (): TableSchema {
                    throw new \RuntimeException('transform failed');
                },
            );
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), 'transform failed');
        Assert::same(file_get_contents($this->schemaPath()), $before);
    }

    #[Test]
    public function setColumnCommentUnknownColumnThrowsAndWritesNothing(): void
    {
        $before = file_get_contents($this->schemaPath());

        $caught = null;

        try {
            $this->db->setColumnComment('main', 'ghost', 'text');
        } catch (StorageException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getErrorKey(), 'COLUMN_NOT_FOUND');
        Assert::same(file_get_contents($this->schemaPath()), $before);
    }

    // -- audit regressions -------------------------------------------------

    #[Test]
    public function numericTableNameSurvivesUnrelatedMutation(): void
    {
        // json_decode coerces "2024" object keys to int; the RMW round-trip
        // must not drop such tables.
        $registry = new SchemaRegistry(new JsonStorage($this->dbDir));
        $registry->registerTable(TableSchema::create(
            name: '2024',
            uniqueConstraints: [],
            columns: ['id' => 'int'],
            indexes: [],
        ));

        $this->db->setTableComment('main', 'unrelated change');

        $fresh = new SchemaRegistry(new JsonStorage($this->dbDir));
        Assert::true($fresh->hasTable('2024'));
    }

    #[Test]
    public function sameSecondSameSizeChangeIsVisibleToReader(): void
    {
        // Two same-length writes within one mtime second share (mtime,size);
        // the inode from the atomic rename must still invalidate the cache.
        $reader = new SchemaRegistry(new JsonStorage($this->dbDir));
        $writer = new SchemaRegistry(new JsonStorage($this->dbDir));

        $writer->updateTable(
            'main',
            static fn (TableSchema $t): TableSchema => $t
                ->withTableComment('AAAA'),
        );
        Assert::same($reader->getTable('main')->tableComment, 'AAAA');

        $writer->updateTable(
            'main',
            static fn (TableSchema $t): TableSchema => $t
                ->withTableComment('BBBB'),
        );
        Assert::same($reader->getTable('main')->tableComment, 'BBBB');
    }

    #[Test]
    public function nestedTransactionOnSameFileThrows(): void
    {
        $storage = new JsonStorage($this->dbDir);

        $caught = null;

        try {
            $storage->transaction(
                'meta.json',
                static function (array $data, $h) use ($storage): void {
                    $storage->transaction(
                        'meta.json',
                        static fn (array $inner): array => $inner,
                    );
                },
            );
        } catch (StorageException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getErrorKey(), 'LOCK_ORDER_VIOLATION');
    }

    #[Test]
    public function replaceTableAfterForeignUnregisterThrowsNotFound(): void
    {
        $registryA = new SchemaRegistry(new JsonStorage($this->dbDir));
        $registryB = new SchemaRegistry(new JsonStorage($this->dbDir));

        Assert::true($registryA->hasTable('main'));
        $mainSchema = $registryB->getTable('main');

        $registryA->unregisterTable('main');

        $caught = null;

        try {
            $registryB->replaceTable($mainSchema->withTableComment('late'));
        } catch (StorageException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getErrorKey(), 'TABLE_NOT_FOUND');
    }

    // -- unregisterTable ---------------------------------------------------

    #[Test]
    public function unregisterTableIsIdempotentAndSkipsWrite(): void
    {
        $registry = new SchemaRegistry(new JsonStorage($this->dbDir));

        $registry->unregisterTable('main');
        Assert::false($registry->hasTable('main'));

        $statBefore = stat($this->schemaPath());
        \assert($statBefore !== false);

        // Second removal is a no-op: nothing rewritten.
        $registry->unregisterTable('main');
        clearstatcache();
        $statAfter = stat($this->schemaPath());
        \assert($statAfter !== false);

        Assert::same($statAfter['mtime'], $statBefore['mtime']);
        Assert::same($statAfter['size'], $statBefore['size']);
        Assert::same($statAfter['ino'], $statBefore['ino']);
    }

    // -- helpers -----------------------------------------------------------

    private function schemaPath(): string
    {
        return $this->dbDir . '/information_schema.json';
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
