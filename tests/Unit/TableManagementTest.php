<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\InMemoryCache;
use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Mapping\DtoRegistry;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Tests\Support\Dto\LabelDto;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for JsonDataProvider::dropTable() and migrateColumns(): table removal
 * and column-level schema synchronisation.
 *
 * Each test owns an isolated on-disk DB (unique path, own InMemoryCache) so the
 * destructive operations never touch the shared Fixture.
 *
 * Contract under test:
 *  - dropTable removes schema + meta + files + bound DTO, is idempotent, drops
 *    the table's relations, and (via the schema serializer) does NOT wipe the
 *    onDelete/onUpdate of surviving relations;
 *  - migrateColumns adds (with type-appropriate defaults), drops and reorders
 *    columns, keeps indexes in sync, and refuses type changes, not-null columns
 *    without a default on a non-empty table, and constraint/index fields that
 *    reference a missing column.
 */
final class TableManagementTest
{
    private const string TMP = '/tmp/jp-table-mgmt-tests';

    private static int $seq = 0;

    private string $dbPath = '';

    #[BeforeTest]
    public function setUp(): void
    {
        if (!is_dir(self::TMP)) {
            mkdir(self::TMP, 0755, true);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->rmrf(self::TMP);
    }

    // ------------------------------------------------------------------ drop

    #[Test]
    public function dropRemovesDataSchemaMeta(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 'logs',
            columns: ['id' => 'int', 'msg' => 'string'],
        ));
        $db->table('logs')->insertByArray(['msg' => 'hello']);

        $db->dropTable('logs');

        Assert::false($db->hasTable('logs'));
        Assert::false(is_dir($this->dbPath . '/logs'));
        Assert::null($this->metaEntry('logs'));
        Assert::false(isset($this->schemaTables()['logs']));
    }

    #[Test]
    public function dropIsIdempotent(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 't',
            columns: ['id' => 'int', 'a' => 'string'],
        ));

        $db->dropTable('unknown');
        $db->dropTable('t');
        $db->dropTable('t');

        Assert::false($db->hasTable('t'));
    }

    #[Test]
    public function dropRemovesOwnRelations(): void
    {
        $db = $this->makeDb();
        $this->buildUsersOrders($db);
        $this->injectRelation($db, onDelete: 'cascade', onUpdate: 'restrict');

        $db->dropTable('users');

        Assert::false(isset($this->schemaTables()['users']));
        Assert::true(isset($this->schemaTables()['orders']));
        Assert::count($this->schemaRelations(), 0);
        Assert::count($db->readAll('orders'), 3);
    }

    #[Test]
    public function dropKeepsOtherFkActions(): void
    {
        $db = $this->makeDb();
        $this->buildUsersOrders($db);
        $db->createTable(TableSchema::create(
            name: 'logs',
            columns: ['id' => 'int', 'msg' => 'string'],
        ));
        $this->injectRelation($db, onDelete: 'cascade', onUpdate: 'restrict');

        // Dropping an unrelated table re-serializes the schema; the
        // orders->users relation must keep its FK actions rather than silently
        // reset to none.
        $db->dropTable('logs');

        $relation = $this->schemaRelations()[0] ?? [];
        Assert::same($relation['from'] ?? null, 'orders');
        Assert::same($relation['onDelete'] ?? null, 'cascade');
        Assert::same($relation['onUpdate'] ?? null, 'restrict');

        $this->reloadSchema($db);
        $reloaded = $this->schemaRegistry($db)->getChildRelations('users');
        Assert::count($reloaded, 1);
        Assert::same($reloaded[0]->onDelete->value, 'cascade');
        Assert::same($reloaded[0]->onUpdate->value, 'restrict');
    }

    #[Test]
    public function dropUnregistersDto(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 'dto_labels',
            columns: ['id' => 'int', 'label' => 'string'],
        ));
        $db->registerDto(LabelDto::class);

        Assert::notNull($this->dtoRegistry($db)->forTable('dto_labels'));

        $db->dropTable('dto_labels');

        Assert::null($this->dtoRegistry($db)->forTable('dto_labels'));
    }

    // -------------------------------------------------------------- migrate

    #[Test]
    public function migrateAddsColumns(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 't',
            columns: ['id' => 'int', 'a' => 'string'],
        ));
        $db->table('t')->insertByArray(['a' => 'x']);

        $result = $db->migrateColumns(TableSchema::create(
            name: 't',
            columns: [
                'id'  => 'int',
                'a'   => 'string',
                'num' => 'int',
                'opt' => 'string|null',
            ],
        ));

        Assert::same($result['added'], ['num', 'opt']);
        Assert::same($result['dropped'], []);

        $row = $db->readAll('t')[0];
        Assert::same($row['num'], 0);
        Assert::null($row['opt']);
        Assert::same($row['a'], 'x');
    }

    #[Test]
    public function migrateDropsColumns(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 't',
            columns: ['id' => 'int', 'keep' => 'string', 'gone' => 'string'],
        ));
        $db->table('t')->insertByArray(['keep' => 'k', 'gone' => 'g']);

        $result = $db->migrateColumns(TableSchema::create(
            name: 't',
            columns: ['id' => 'int', 'keep' => 'string'],
        ));

        Assert::same($result['dropped'], ['gone']);
        $row = $db->readAll('t')[0];
        Assert::false(\array_key_exists('gone', $row));
        Assert::same($row['keep'], 'k');
    }

    #[Test]
    public function migrateReordersColumns(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 't',
            columns: ['id' => 'int', 'a' => 'string', 'b' => 'int'],
        ));
        $db->table('t')->insertByArray(['a' => 'x', 'b' => 5]);

        // add 'c' and reorder to id, b, a, c
        $db->migrateColumns(TableSchema::create(
            name: 't',
            columns: [
                'id' => 'int',
                'b'  => 'int',
                'a'  => 'string',
                'c'  => 'int|null',
            ],
        ));

        Assert::same($db->columnNames('t'), ['id', 'b', 'a', 'c']);
        Assert::same(
            array_keys($this->schemaTables()['t']['columns']),
            ['id', 'b', 'a', 'c'],
        );
        Assert::same(array_keys($db->readAll('t')[0]), ['id', 'b', 'a', 'c']);
    }

    #[Test]
    public function migrateRebuildsIndexesAndMeta(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 't',
            columns: ['id' => 'int', 'a' => 'string', 'b' => 'int'],
            indexes: [new IndexSchema(
                'idx_b',
                [new IndexFieldSchema('b', SortDirectionEnum::ASC)],
            )],
        ));
        $db->table('t')->insertByArray(['a' => 'x', 'b' => 9]);
        $db->table('t')->insertByArray(['a' => 'y', 'b' => 1]);
        $db->table('t')->insertByArray(['a' => 'z', 'b' => 5]);

        $db->migrateColumns(TableSchema::create(
            name: 't',
            columns: [
                'id' => 'int',
                'a'  => 'string',
                'b'  => 'int',
                'c'  => 'int|null',
            ],
            indexes: [new IndexSchema(
                'idx_b',
                [new IndexFieldSchema('b', SortDirectionEnum::ASC)],
            )],
        ));

        $ordered = $db->select(
            't',
            [],
            [new OrderBy('b', SortDirectionEnum::ASC)],
        );
        Assert::same(array_column($ordered, 'b'), [1, 5, 9]);

        $meta = $this->metaEntry('t');
        \assert($meta !== null);
        Assert::same($meta['lineCount'], 3);
    }

    #[Test]
    public function migrateNoOpOnSameColumns(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 't',
            columns: ['id' => 'int', 'a' => 'string'],
        ));

        $result = $db->migrateColumns(TableSchema::create(
            name: 't',
            columns: ['id' => 'int', 'a' => 'string'],
        ));

        Assert::same($result, ['added' => [], 'dropped' => []]);
    }

    #[Test]
    public function migrateRejectsTypeChange(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 't',
            columns: ['id' => 'int', 'a' => 'int'],
        ));
        $db->table('t')->insertByArray(['a' => 42]);

        $key = $this->catchKey(static fn () => $db->migrateColumns(
            TableSchema::create(
                name: 't',
                columns: ['id' => 'int', 'a' => 'string'],
            ),
        ));

        Assert::same($key, 'MIGRATE_COLUMN_TYPE_CHANGE');
        Assert::same($db->readAll('t')[0]['a'], 42);
        Assert::same($db->columnNames('t'), ['id', 'a']);
    }

    #[Test]
    public function migrateRejectsNotNullNoDefault(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 'ev',
            columns: ['id' => 'int', 'title' => 'string'],
        ));
        $db->table('ev')->insertByArray(['title' => 'x']);

        foreach (['datetime', 'date', 'month', 'day', 'year'] as $type) {
            $key = $this->catchKey(static fn () => $db->migrateColumns(
                TableSchema::create(
                    name: 'ev',
                    columns: ['id' => 'int', 'title' => 'string', 'f' => $type],
                ),
            ));
            Assert::same($key, 'MIGRATE_COLUMN_NO_DEFAULT', $type);
        }

        // the nullable variant is accepted and defaults to null
        $db->migrateColumns(TableSchema::create(
            name: 'ev',
            columns: [
                'id'    => 'int',
                'title' => 'string',
                'f'     => 'datetime|null',
            ],
        ));
        Assert::null($db->readAll('ev')[0]['f']);
    }

    #[Test]
    public function migrateAllowsNotNullOnEmptyTable(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 'em',
            columns: ['id' => 'int', 'a' => 'string'],
        ));

        $result = $db->migrateColumns(TableSchema::create(
            name: 'em',
            columns: ['id' => 'int', 'a' => 'string', 'm' => 'month'],
        ));

        Assert::same($result['added'], ['m']);
        Assert::same($db->columnNames('em'), ['id', 'a', 'm']);
    }

    #[Test]
    public function migrateRejectsBadConstraintField(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 'u',
            columns: ['id' => 'int', 'email' => 'string', 'x' => 'string'],
            uniqueConstraints: [new UniqueConstraint('uq_email', ['email'])],
        ));
        $db->table('u')->insertByArray(['email' => 'a@x', 'x' => '1']);

        $key = $this->catchKey(static fn () => $db->migrateColumns(
            TableSchema::create(
                name: 'u',
                columns: ['id' => 'int', 'x' => 'string'],
                uniqueConstraints: [
                    new UniqueConstraint('uq_email', ['email']),
                ],
            ),
        ));

        Assert::same($key, 'MIGRATE_FIELD_UNKNOWN_COLUMN');
        Assert::true(\in_array('email', $db->columnNames('u'), true));
        Assert::count($db->readAll('u'), 1);
    }

    #[Test]
    public function migrateRejectsBadIndexField(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 'u',
            columns: ['id' => 'int', 'a' => 'string'],
        ));

        $key = $this->catchKey(static fn () => $db->migrateColumns(
            TableSchema::create(
                name: 'u',
                columns: ['id' => 'int', 'a' => 'string'],
                indexes: [new IndexSchema(
                    'idx_ghost',
                    [new IndexFieldSchema('ghost', SortDirectionEnum::ASC)],
                )],
            ),
        ));

        Assert::same($key, 'MIGRATE_FIELD_UNKNOWN_COLUMN');
    }

    #[Test]
    public function migrateDeletesOrphanIndex(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 'p',
            columns: ['id' => 'int', 'b' => 'int', 'c' => 'string'],
            indexes: [new IndexSchema(
                'idx_b',
                [new IndexFieldSchema('b', SortDirectionEnum::ASC)],
            )],
        ));
        $db->table('p')->insertByArray(['b' => 1, 'c' => 'x']);
        Assert::true(file_exists($this->dbPath . '/p/idx_b.index.ndjson'));

        // drop 'b' and its index together
        $db->migrateColumns(TableSchema::create(
            name: 'p',
            columns: ['id' => 'int', 'c' => 'string'],
        ));

        Assert::false(file_exists($this->dbPath . '/p/idx_b.index.ndjson'));
        Assert::true(file_exists($this->dbPath . '/p/pk.index.ndjson'));
        Assert::false(\array_key_exists('b', $db->readAll('p')[0]));
    }

    #[Test]
    public function migrateCreatesNewIndex(): void
    {
        $db = $this->makeDb();
        $db->createTable(TableSchema::create(
            name: 'q',
            columns: ['id' => 'int', 'a' => 'string'],
        ));
        $db->table('q')->insertByArray(['a' => 'm']);
        $db->table('q')->insertByArray(['a' => 'k']);

        $db->migrateColumns(TableSchema::create(
            name: 'q',
            columns: ['id' => 'int', 'a' => 'string', 'w' => 'int|null'],
            indexes: [new IndexSchema(
                'idx_a',
                [new IndexFieldSchema('a', SortDirectionEnum::ASC)],
            )],
        ));

        Assert::true(file_exists($this->dbPath . '/q/idx_a.index.ndjson'));
        $ordered = $db->select(
            'q',
            [],
            [new OrderBy('a', SortDirectionEnum::ASC)],
        );
        Assert::same(array_column($ordered, 'a'), ['k', 'm']);
    }

    #[Test]
    public function migrateThrowsOnUnknownTable(): void
    {
        $db = $this->makeDb();

        $key = $this->catchKey(static fn () => $db->migrateColumns(
            TableSchema::create(
                name: 'nope',
                columns: ['id' => 'int', 'a' => 'string'],
            ),
        ));

        Assert::same($key, 'TABLE_NOT_FOUND');
    }

    // ------------------------------------------------------------- helpers

    private function makeDb(): JsonDataProvider
    {
        self::$seq++;
        $this->dbPath = self::TMP . '/db_' . self::$seq;
        $this->rmrf($this->dbPath);

        return JsonDataProvider::createDatabase(
            $this->dbPath,
            new InMemoryCache(),
        );
    }

    private function buildUsersOrders(JsonDataProvider $db): void
    {
        $db->createTable(TableSchema::create(
            name: 'users',
            columns: ['id' => 'int', 'name' => 'string'],
        ));
        $db->createTable(TableSchema::create(
            name: 'orders',
            columns: ['id' => 'int', 'user_id' => 'int'],
        ));

        $u1 = $db->table('users')->insertByArray(['name' => 'Alice']);
        $u2 = $db->table('users')->insertByArray(['name' => 'Bob']);
        $db->table('orders')->insertByArray(['user_id' => $u1]);
        $db->table('orders')->insertByArray(['user_id' => $u1]);
        $db->table('orders')->insertByArray(['user_id' => $u2]);
    }

    /**
     * Writes an orders->users relation straight into information_schema.json
     * and reloads the registry so the provider picks it up.
     */
    private function injectRelation(
        JsonDataProvider $db,
        string $onDelete,
        string $onUpdate,
    ): void {
        $storage = new JsonStorage($this->dbPath);

        /** @var array{relations?: array<int,array<string,string>>} $schema */
        $schema = $storage->read('information_schema.json');
        $schema['relations'] = [[
            'from'       => 'orders',
            'foreignKey' => 'user_id',
            'to'         => 'users',
            'references' => 'id',
            'type'       => 'belongsTo',
            'onDelete'   => $onDelete,
            'onUpdate'   => $onUpdate,
        ]];
        $storage->write('information_schema.json', $schema);

        $this->reloadSchema($db);
    }

    /**
     * @param callable():mixed $fn
     */
    private function catchKey(callable $fn): string
    {
        try {
            $fn();
        } catch (StorageException $e) {
            return $e->getErrorKey();
        }

        return '';
    }

    /**
     * @return array<string,array{columns:array<string,string>}>
     */
    private function schemaTables(): array
    {
        /**
         * @var array{
         *     tables: array<string,array{columns:array<string,string>}>
         * } $schema
         */
        $schema = (new JsonStorage($this->dbPath))
            ->read('information_schema.json');

        return $schema['tables'];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function schemaRelations(): array
    {
        /** @var array{relations: array<int,array<string,string>>} $schema */
        $schema = (new JsonStorage($this->dbPath))
            ->read('information_schema.json');

        return $schema['relations'];
    }

    /**
     * @return null|array{lastInsertedId:int,lineCount:int}
     */
    private function metaEntry(string $table): array | null
    {
        /** @var array<string,array{lastInsertedId:int,lineCount:int}> $meta */
        $meta = (new JsonStorage($this->dbPath))->read('meta.json');

        return $meta[$table] ?? null;
    }

    private function reloadSchema(JsonDataProvider $db): void
    {
        $this->schemaRegistry($db)->reload();
    }

    private function schemaRegistry(JsonDataProvider $db): SchemaRegistry
    {
        $registry = (new \ReflectionProperty(JsonDataProvider::class, 'schema'))
            ->getValue($db);
        \assert($registry instanceof SchemaRegistry);

        return $registry;
    }

    private function dtoRegistry(JsonDataProvider $db): DtoRegistry
    {
        $registry = (new \ReflectionProperty(
            JsonDataProvider::class,
            'dtoRegistry',
        ))->getValue($db);
        \assert($registry instanceof DtoRegistry);

        return $registry;
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);

            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
