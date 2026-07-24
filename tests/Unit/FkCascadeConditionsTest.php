<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\JsonStorage;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for engine-built FK cascade conditions: they are constructed from
 * STORED values and must bypass the user-input condition validation —
 * re-encoding would shift an already-UTC temporal value a second time
 * (silently orphaning children under a non-UTC PHP timezone), and strict
 * user typing would make a parent with a type-skewed FK schema
 * undeletable.
 */
final class FkCascadeConditionsTest
{
    private const string DB_PATH = '/tmp/jp-fkcascade-tests';

    private string $dbDir;

    private JsonDataProvider $db;

    private string $tzBackup = 'UTC';

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);
        $this->tzBackup = date_default_timezone_get();

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        date_default_timezone_set($this->tzBackup);
        $this->removeDir(self::DB_PATH);
    }

    #[Test]
    public function temporalCascadeDeletesChildrenUnderNonUtcTimezone(): void
    {
        date_default_timezone_set('Europe/Moscow');

        $this->db->createTable(TableSchema::create(
            name: 'events',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'dt' => 'datetime'],
            indexes: [],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'logs',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'event_dt' => 'datetime'],
            indexes: [],
        ));
        $this->injectRelation(
            from: 'logs',
            foreignKey: 'event_dt',
            to: 'events',
            references: 'dt',
            onDelete: 'cascade',
        );

        $parentId = $this->db->insert(
            'events',
            ['dt' => '2026-01-01 12:00:00'],
        );
        $this->db->insert('logs', ['event_dt' => '2026-01-01 12:00:00']);

        $deleted = $this->db->table('events')->deleteById($parentId);

        Assert::true($deleted);
        Assert::same(
            $this->db->table('logs')->count(),
            0,
            'the cascade must match the stored UTC value, not re-shift it',
        );
    }

    #[Test]
    public function typeSkewedExecutableEdgeFailsLoudlyBeforeAnyWrite(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'parents',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'ref' => 'float'],
            indexes: [],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'children',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'fk' => 'int|null'],
            indexes: [],
        ));
        $this->injectRelation(
            from: 'children',
            foreignKey: 'fk',
            to: 'parents',
            references: 'ref',
            onDelete: 'cascade',
        );

        $parentId = $this->db->insert('parents', ['ref' => 5.5]);
        $this->db->insert('children', ['fk' => 7]);

        try {
            $this->db->table('parents')->deleteById($parentId);
            Assert::fail(
                'a type-skewed EXECUTABLE edge must fail loudly in the '
                    . 'plan phase instead of silently matching nothing',
            );
        } catch (\AV\JsonProvider\Exception\StorageException $e) {
            Assert::same($e->getErrorKey(), 'RELATION_TYPE_MISMATCH');
        }

        Assert::same(
            $this->db->table('parents')->count(),
            1,
            'the delete must abort with the disk untouched',
        );
        Assert::same($this->db->table('children')->count(), 1);
    }

    #[Test]
    public function typeSkewedDeadNoActionEdgeDoesNotBlockDelete(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'parents',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'ref' => 'float'],
            indexes: [],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'children',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'fk' => 'int|null'],
            indexes: [],
        ));
        $this->injectRelation(
            from: 'children',
            foreignKey: 'fk',
            to: 'parents',
            references: 'ref',
            onDelete: 'noAction',
        );

        $parentId = $this->db->insert('parents', ['ref' => 5.5]);
        $this->db->insert('children', ['fk' => 7]);

        $deleted = $this->db->table('parents')->deleteById($parentId);

        Assert::true(
            $deleted,
            'a dead NO_ACTION edge, even type-skewed, must not block',
        );
        Assert::same($this->db->table('parents')->count(), 0);
        Assert::same($this->db->table('children')->count(), 1);
    }

    #[Test]
    public function intCascadeStillDeletesMatchingChildren(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'users',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'name' => 'string'],
            indexes: [],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'orders',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'user_id' => 'int'],
            indexes: [],
        ));
        $this->injectRelation(
            from: 'orders',
            foreignKey: 'user_id',
            to: 'users',
            references: 'id',
            onDelete: 'cascade',
        );

        $userId = $this->db->insert('users', ['name' => 'a']);
        $this->db->insert('orders', ['user_id' => $userId]);
        $this->db->insert('orders', ['user_id' => $userId]);

        $this->db->table('users')->deleteById($userId);

        Assert::same($this->db->table('orders')->count(), 0);
    }

    // -- helpers -----------------------------------------------------------

    private function injectRelation(
        string $from,
        string $foreignKey,
        string $to,
        string $references,
        string $onDelete,
    ): void {
        $storage = new JsonStorage($this->dbDir);

        /** @var array{relations?: array<int,array<string,string>>} $schema */
        $schema = $storage->read('information_schema.json');
        $schema['relations'] = [[
            'from'       => $from,
            'foreignKey' => $foreignKey,
            'to'         => $to,
            'references' => $references,
            'type'       => 'belongsTo',
            'onDelete'   => $onDelete,
            'onUpdate'   => 'noAction',
        ]];
        $storage->write('information_schema.json', $schema);

        $registry = (new \ReflectionProperty(
            JsonDataProvider::class,
            'schema',
        ))->getValue($this->db);
        \assert($registry instanceof \AV\JsonProvider\Registry\SchemaRegistry);
        $registry->reload();
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
