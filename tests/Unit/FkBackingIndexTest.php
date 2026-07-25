<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IssueCategory;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the FK backing index: provisioning by the relation DDL
 * (service "_fk_<col>" or a reused single-column user index), lifecycle
 * on dropRelation/dropIndex, invisibility to query planning, the restrict
 * probe riding the trusted index (including the INDEX_UNRELIABLE seam and
 * the honest degradation on a stale index), the explicit configuration
 * error for legacy restrict edges, and the repair back-fill.
 */
final class FkBackingIndexTest
{
    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: 'users',
            columns: ['id' => 'int'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'posts',
            columns: ['id' => 'int', 'userId' => 'int|null'],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function cascadeRelationProvisionsAServiceIndex(): void
    {
        $this->db->addRelation($this->cascadeRelation());

        $relation = $this->db->relations('users')[0];
        Assert::same($relation->backingIndex, '_fk_userId');

        $index = $this->indexByName('posts', '_fk_userId');
        Assert::true($index !== null);
        Assert::true($index->isService);
        Assert::count($index->fields, 1);
        Assert::same($index->fields[0]->field, 'userId');
        Assert::true(
            is_file($this->dbDir . '/posts/_fk_userId.index.ndjson'),
            'the service index must exist physically',
        );
    }

    #[Test]
    public function restrictRelationReusesAUserSingleColumnIndex(): void
    {
        $this->db->addIndex('posts', new IndexSchema(
            name: 'byUser',
            fields: [
                new IndexFieldSchema('userId', SortDirectionEnum::ASC),
            ],
        ));

        $this->db->addRelation($this->restrictRelation());

        $relation = $this->db->relations('users')[0];
        Assert::same(
            $relation->backingIndex,
            'byUser',
            'an existing covering user index must be reused',
        );
        Assert::same(
            $this->indexByName('posts', '_fk_userId'),
            null,
            'no service twin may be created',
        );
    }

    #[Test]
    public function noActionAndSetNullNeedNoBacking(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::SET_NULL,
        ));

        $relation = $this->db->relations('users')[0];
        Assert::same($relation->backingIndex, null);
        Assert::same($this->indexByName('posts', '_fk_userId'), null);
    }

    #[Test]
    public function dropRelationDropsItsServiceIndexButKeepsUserOnes(): void
    {
        $this->db->addRelation($this->cascadeRelation());
        Assert::true(
            is_file($this->dbDir . '/posts/_fk_userId.index.ndjson'),
        );

        $this->db->dropRelation('posts', 'userId', 'users');

        Assert::same($this->indexByName('posts', '_fk_userId'), null);
        Assert::false(
            is_file($this->dbDir . '/posts/_fk_userId.index.ndjson'),
            'the unused service index file must be removed',
        );

        $this->db->addIndex('posts', new IndexSchema(
            name: 'byUser',
            fields: [
                new IndexFieldSchema('userId', SortDirectionEnum::ASC),
            ],
        ));
        $this->db->addRelation($this->restrictRelation());
        $this->db->dropRelation('posts', 'userId', 'users');

        Assert::true($this->indexByName('posts', 'byUser') !== null);
        Assert::true(
            is_file($this->dbDir . '/posts/byUser.index.ndjson'),
        );
    }

    #[Test]
    public function sharedServiceBackingSurvivesUntilTheLastRelation(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'admins',
            columns: ['id' => 'int'],
        ));
        $this->db->addRelation($this->cascadeRelation());
        $this->db->addRelation(new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'admins',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::RESTRICT,
        ));

        $this->db->dropRelation('posts', 'userId', 'users');

        Assert::true(
            $this->indexByName('posts', '_fk_userId') !== null,
            'the second relation still rides the shared service index',
        );

        $this->db->dropRelation('posts', 'userId', 'admins');

        Assert::same($this->indexByName('posts', '_fk_userId'), null);
    }

    #[Test]
    public function droppingAUserBackingIndexRecreatesAServiceOne(): void
    {
        $this->db->addIndex('posts', new IndexSchema(
            name: 'byUser',
            fields: [
                new IndexFieldSchema('userId', SortDirectionEnum::ASC),
            ],
        ));
        $this->db->addRelation($this->restrictRelation());

        $this->db->dropIndex('posts', 'byUser');

        Assert::same($this->indexByName('posts', 'byUser'), null);
        $service = $this->indexByName('posts', '_fk_userId');
        Assert::true(
            $service !== null && $service->isService,
            'the FK must not lose its probe when its user index goes',
        );
        Assert::same(
            $this->db->relations('users')[0]->backingIndex,
            '_fk_userId',
        );

        $userId = $this->db->insert('users', []);
        $this->db->insert('posts', ['userId' => $userId]);

        try {
            $this->db->table('users')->deleteById($userId);
            Assert::fail('restrict must block through the new backing');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'FOREIGN_KEY_RESTRICT');
        }
    }

    #[Test]
    public function serviceIndexCannotBeDroppedOrAddedDirectly(): void
    {
        $this->db->addRelation($this->cascadeRelation());

        try {
            $this->db->dropIndex('posts', '_fk_userId');
            Assert::fail('service index lifecycle belongs to relation DDL');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'RESERVED_INDEX_NAME');
        }

        try {
            $this->db->addIndex('posts', new IndexSchema(
                name: '_fk_extra',
                fields: [
                    new IndexFieldSchema('userId', SortDirectionEnum::ASC),
                ],
            ));
            Assert::fail('the _fk_ namespace is reserved');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'RESERVED_INDEX_NAME');
        }
    }

    #[Test]
    public function serviceIndexIsInvisibleToSelects(): void
    {
        $this->db->addRelation($this->cascadeRelation());

        $u1 = $this->db->insert('users', []);
        $u2 = $this->db->insert('users', []);
        $this->db->insert('posts', ['userId' => $u1]);
        $this->db->insert('posts', ['userId' => $u2]);
        $this->db->insert('posts', ['userId' => $u1]);

        $viaEq = $this->db->table('posts')
            ->where('userId', '=', $u1)
            ->selectAllByArray();
        Assert::count($viaEq, 2);

        $ordered = $this->db->table('posts')
            ->orderBy('userId', 'asc')
            ->selectAllByArray();
        Assert::count($ordered, 3);
    }

    #[Test]
    public function legacyRestrictWithoutBackingFailsExplicitly(): void
    {
        $this->injectRelation(
            from: 'posts',
            foreignKey: 'userId',
            to: 'users',
            references: 'id',
            type: 'belongsTo',
            onDelete: 'restrict',
        );

        $userId = $this->db->insert('users', []);
        $this->db->insert('posts', ['userId' => $userId]);

        try {
            $this->db->table('users')->deleteById($userId);
            Assert::fail(
                'a restrict probe without a backing index must be a loud '
                    . 'configuration error, never a silent scan',
            );
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'FK_BACKING_INDEX_MISSING');
        }
    }

    #[Test]
    public function repairProvisionsTheMissingBackingIndex(): void
    {
        $this->injectRelation(
            from: 'posts',
            foreignKey: 'userId',
            to: 'users',
            references: 'id',
            type: 'belongsTo',
            onDelete: 'restrict',
        );

        $report = $this->db->validate();
        $found = array_filter(
            $report->issues,
            static fn ($i): bool => $i
                ->category === IssueCategory::FK_BACKING_INDEX_MISSING,
        );
        Assert::count($found, 1);

        $this->db->repair();

        Assert::same(
            $this->db->relations('users')[0]->backingIndex,
            '_fk_userId',
        );
        $service = $this->indexByName('posts', '_fk_userId');
        Assert::true($service !== null && $service->isService);

        $userId = $this->db->insert('users', []);
        $this->db->insert('posts', ['userId' => $userId]);

        try {
            $this->db->table('users')->deleteById($userId);
            Assert::fail('restrict must block after the repair');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'FOREIGN_KEY_RESTRICT');
        }
    }

    #[Test]
    public function corruptBackingIndexRaisesIndexUnreliable(): void
    {
        $this->db->addRelation($this->restrictRelation());

        $userId = $this->db->insert('users', []);
        $this->db->insert('posts', ['userId' => $userId]);

        file_put_contents(
            $this->dbDir . '/posts/_fk_userId.index.ndjson',
            '{"key":"broken","line":0}' . "\n",
        );

        try {
            $this->db->table('users')->deleteById($userId);
            Assert::fail(
                'a structurally corrupt backing must fail loudly, not '
                    . 'return "no references"',
            );
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INDEX_UNRELIABLE');
        }

        Assert::same($this->db->table('users')->count(), 1);
    }

    #[Test]
    public function staleBackingIndexDegradesToAnHonestScan(): void
    {
        $this->db->addRelation($this->restrictRelation());

        $userId = $this->db->insert('users', []);

        file_put_contents(
            $this->dbDir . '/posts/posts.ndjson',
            '{"id":77,"userId":' . $userId . '}' . "\n",
            FILE_APPEND,
        );
        $this->db->invalidateCache('posts');

        try {
            $this->db->table('users')->deleteById($userId);
            Assert::fail('the scan fallback must see the foreign row');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'FOREIGN_KEY_RESTRICT');
        }
    }

    #[Test]
    public function renameColumnRenamesTheServiceBackingWithIt(): void
    {
        $this->db->addRelation($this->cascadeRelation());

        $this->db->createTable(TableSchema::create(
            name: 'other',
            columns: ['id' => 'int'],
        ));
        $this->db->renameColumn('posts', 'userId', 'ownerId');

        Assert::same($this->indexByName('posts', '_fk_userId'), null);
        $renamed = $this->indexByName('posts', '_fk_ownerId');
        Assert::true($renamed !== null && $renamed->isService);
        Assert::same($renamed->fields[0]->field, 'ownerId');
        Assert::same(
            $this->db->relations('users')[0]->backingIndex,
            '_fk_ownerId',
        );
        Assert::false(
            is_file($this->dbDir . '/posts/_fk_userId.index.ndjson'),
            'the old service file must not linger',
        );

        $userId = $this->db->insert('users', []);
        $this->db->insert('posts', ['ownerId' => $userId]);
        $this->db->table('users')->deleteById($userId);
        Assert::same($this->db->table('posts')->count(), 0);
    }

    #[Test]
    public function dropTableOfTheParentReleasesTheServiceBacking(): void
    {
        $this->db->addRelation($this->cascadeRelation());
        Assert::true($this->indexByName('posts', '_fk_userId') !== null);

        $this->db->dropTable('users');

        Assert::count($this->db->relations(), 0);
        Assert::same(
            $this->indexByName('posts', '_fk_userId'),
            null,
            'the backing of the removed relations must go with them',
        );
        Assert::false(
            is_file($this->dbDir . '/posts/_fk_userId.index.ndjson'),
        );
    }

    #[Test]
    public function orphanedServiceIndexIsReportedAndRepaired(): void
    {
        $this->db->addRelation($this->cascadeRelation());
        $storage = new JsonStorage($this->dbDir);

        /** @var array{relations?: array<int,mixed>} $schema */
        $schema = $storage->read('information_schema.json');
        $schema['relations'] = [];
        $storage->write('information_schema.json', $schema);

        $report = $this->db->validate();
        $found = array_filter(
            $report->issues,
            static fn ($i): bool => $i
                ->category === IssueCategory::FK_BACKING_INDEX_ORPHANED,
        );
        Assert::count($found, 1);

        $this->db->repair();

        Assert::same($this->indexByName('posts', '_fk_userId'), null);
        Assert::false(
            is_file($this->dbDir . '/posts/_fk_userId.index.ndjson'),
        );
    }

    #[Test]
    public function repairReusesACoveringUserIndexAsBacking(): void
    {
        $this->db->addIndex('posts', new IndexSchema(
            name: 'byUser',
            fields: [
                new IndexFieldSchema('userId', SortDirectionEnum::ASC),
            ],
        ));
        $this->injectRelation(
            from: 'posts',
            foreignKey: 'userId',
            to: 'users',
            references: 'id',
            type: 'belongsTo',
            onDelete: 'restrict',
        );

        $this->db->repair();

        Assert::same(
            $this->db->relations('users')[0]->backingIndex,
            'byUser',
            'repair must mirror the addRelation provisioning: reuse, '
                . 'not a duplicate service twin',
        );
        Assert::same($this->indexByName('posts', '_fk_userId'), null);
    }

    #[Test]
    public function cascadeProbeSkipsUntouchedChildrenCorrectly(): void
    {
        $this->db->addRelation($this->cascadeRelation());

        $u1 = $this->db->insert('users', []);
        $u2 = $this->db->insert('users', []);
        $this->db->insert('posts', ['userId' => $u2]);

        $this->db->table('users')->deleteById($u1);
        Assert::same($this->db->table('posts')->count(), 1);

        $this->db->table('users')->deleteById($u2);
        Assert::same($this->db->table('posts')->count(), 0);
    }

    private function cascadeRelation(): RelationSchema
    {
        return new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        );
    }

    private function restrictRelation(): RelationSchema
    {
        return new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::RESTRICT,
        );
    }

    private function indexByName(
        string $table,
        string $name,
    ): IndexSchema | null {
        $registry = (new \ReflectionProperty(
            JsonDataProvider::class,
            'schema',
        ))->getValue($this->db);
        \assert($registry instanceof \AV\JsonProvider\Registry\SchemaRegistry);
        $registry->reload();

        foreach ($registry->getTable($table)->indexes as $index) {
            if ($index->name === $name) {
                return $index;
            }
        }

        return null;
    }

    private function injectRelation(
        string $from,
        string $foreignKey,
        string $to,
        string $references,
        string $type,
        string | null $onDelete = null,
        string | null $onUpdate = null,
    ): void {
        $storage = new JsonStorage($this->dbDir);

        /** @var array{relations?: array<int,array<string,string>>} $schema */
        $schema = $storage->read('information_schema.json');
        $entry = [
            'from'       => $from,
            'foreignKey' => $foreignKey,
            'to'         => $to,
            'references' => $references,
            'type'       => $type,
        ];

        if ($onDelete !== null) {
            $entry['onDelete'] = $onDelete;
        }

        if ($onUpdate !== null) {
            $entry['onUpdate'] = $onUpdate;
        }

        $schema['relations'] ??= [];
        $schema['relations'][] = $entry;
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

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-fkbacking-tests');
    }
}
