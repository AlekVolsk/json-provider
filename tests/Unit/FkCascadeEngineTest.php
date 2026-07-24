<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Storage\JsonStorage;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the FK cascade engine: the whole multi-table effect of a
 * delete/update is planned (and validated) before the first byte is
 * written, cyclic and self-referential cascades terminate, restrict
 * follows MySQL immediate semantics against the original state, unique
 * constraints are checked against the final state of the batch, and a
 * failing PREPARE aborts with every data file untouched.
 */
final class FkCascadeEngineTest
{
    private const string DB_PATH = '/tmp/jp-fkengine-tests';

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
    public function cyclicCascadeBetweenTwoTablesTerminates(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'a',
            columns: ['id' => 'int', 'bId' => 'int|null'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'b',
            columns: ['id' => 'int', 'aId' => 'int|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'b',
            foreignKey: 'aId',
            toTable: 'a',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'a',
            foreignKey: 'bId',
            toTable: 'b',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        $a1 = $this->db->insert('a', ['bId' => null]);
        $b1 = $this->db->insert('b', ['aId' => $a1]);
        $this->db->table('a')->where('id', '=', $a1)->updateByArray([
            'bId' => $b1,
        ]);
        $a2 = $this->db->insert('a', ['bId' => null]);

        $this->db->table('a')->deleteById($a1);

        Assert::same(
            array_column($this->db->table('a')->selectAllByArray(), 'id'),
            [$a2],
            'the A<->B cycle must delete a1 and b1 and stop',
        );
        Assert::same($this->db->table('b')->count(), 0);
    }

    #[Test]
    public function selfReferentialCascadeChainDeletesAllDescendants(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'nodes',
            columns: ['id' => 'int', 'parentId' => 'int|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'nodes',
            foreignKey: 'parentId',
            toTable: 'nodes',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        $root = $this->db->insert('nodes', ['parentId' => null]);
        $mid = $this->db->insert('nodes', ['parentId' => $root]);
        $leaf = $this->db->insert('nodes', ['parentId' => $mid]);
        $other = $this->db->insert('nodes', ['parentId' => null]);

        $this->db->table('nodes')->deleteById($root);

        $rawLines = array_values(array_filter(
            explode(
                "\n",
                (string)file_get_contents(
                    $this->dbDir . '/nodes/nodes.ndjson',
                ),
            ),
            static fn (string $line): bool => $line !== '',
        ));
        Assert::count(
            $rawLines,
            1,
            'descendants must not resurrect on disk after the cascade',
        );

        $survivors = array_column(
            $this->db->table('nodes')->selectAllByArray(),
            'id',
        );
        Assert::same($survivors, [$other]);
        Assert::false(\in_array($leaf, $survivors, true));
    }

    #[Test]
    public function batchUpdateUniqueFailureLeavesTheDiskUntouched(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'goods',
            uniqueConstraints: [
                new UniqueConstraint('u_code', ['code', 'grade']),
            ],
            columns: [
                'id'    => 'int',
                'code'  => 'string',
                'grade' => 'int',
            ],
        ));
        $this->db->insert('goods', ['code' => 'a', 'grade' => 1]);
        $this->db->insert('goods', ['code' => 'b', 'grade' => 1]);

        $before = file_get_contents($this->dbDir . '/goods/goods.ndjson');

        try {
            // Both rows match; patching both to code='x' collides on the
            // composite unique key within the SAME batch.
            $this->db->update('goods', [], ['code' => 'x']);
            Assert::fail('two targets collapse into one unique key');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'UNIQUE_VIOLATION');
        }

        Assert::same(
            file_get_contents($this->dbDir . '/goods/goods.ndjson'),
            $before,
            'the unique check runs in the plan phase; nothing is written',
        );
    }

    #[Test]
    public function parentWithCascadeAndRestrictChildren(): void
    {
        $this->setUpParentWithTwoChildren();

        $parentId = 1;

        try {
            $this->db->table('parents')->deleteById($parentId);
            Assert::fail('the restrict child must block the delete');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'FOREIGN_KEY_RESTRICT');
        }

        Assert::same(
            $this->db->table('cascadeKids')->count(),
            1,
            'the cascade child must be intact after the refused delete',
        );
        Assert::same($this->db->table('restrictKids')->count(), 1);
        Assert::same($this->db->table('parents')->count(), 1);
    }

    #[Test]
    public function selfReferentialRestrictRequiresLeavesFirst(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'cats',
            columns: ['id' => 'int', 'parentId' => 'int|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'cats',
            foreignKey: 'parentId',
            toTable: 'cats',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::RESTRICT,
        ));

        $root = $this->db->insert('cats', ['parentId' => null]);
        $leaf = $this->db->insert('cats', ['parentId' => $root]);

        try {
            $this->db->delete('cats', []);
            Assert::fail(
                'MySQL-immediate restrict counts the referencing row even '
                    . 'when the same statement deletes it',
            );
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'FOREIGN_KEY_RESTRICT');
        }

        Assert::same($this->db->table('cats')->count(), 2);

        $this->db->table('cats')->deleteById($leaf);
        $this->db->table('cats')->deleteById($root);
        Assert::same(
            $this->db->table('cats')->count(),
            0,
            'leaves-to-roots deletion must pass',
        );
    }

    #[Test]
    public function multiLevelCascadeWritesEveryTableConsistently(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'a',
            columns: ['id' => 'int'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'b',
            columns: ['id' => 'int', 'aId' => 'int|null'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'c',
            columns: ['id' => 'int', 'bId' => 'int|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'b',
            foreignKey: 'aId',
            toTable: 'a',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'c',
            foreignKey: 'bId',
            toTable: 'b',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        $a = $this->db->insert('a', []);
        $b1 = $this->db->insert('b', ['aId' => $a]);
        $b2 = $this->db->insert('b', ['aId' => $a]);
        $this->db->insert('c', ['bId' => $b1]);
        $this->db->insert('c', ['bId' => $b2]);
        $cFree = $this->db->insert('c', ['bId' => null]);

        $this->db->table('a')->deleteById($a);

        Assert::same($this->db->table('a')->count(), 0);
        Assert::same($this->db->table('b')->count(), 0);
        Assert::same(
            array_column($this->db->table('c')->selectAllByArray(), 'id'),
            [$cFree],
        );

        // Validate every touched table: meta counters, indexes and data
        // must be committed consistently by the two-phase apply.
        $report = $this->db->validate();
        Assert::count($report->issuesBySeverity(
            \AV\JsonProvider\Services\Integrity\IssueSeverity::ERROR,
        ), 0);
    }

    #[Test]
    public function setNullOnDeleteNullsTheChildren(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'users',
            columns: ['id' => 'int'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'tasks',
            columns: ['id' => 'int', 'userId' => 'int|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'tasks',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::SET_NULL,
        ));

        $userId = $this->db->insert('users', []);
        $t1 = $this->db->insert('tasks', ['userId' => $userId]);
        $this->db->insert('tasks', ['userId' => null]);

        $this->db->table('users')->deleteById($userId);

        $tasks = $this->db->table('tasks')->selectAllByArray();
        Assert::count($tasks, 2);

        foreach ($tasks as $task) {
            Assert::same($task['userId'], null);
        }

        Assert::same($tasks[0]['id'], $t1);
    }

    #[Test]
    public function legacySetNullOnNonNullableFkFailsBeforeAnyWrite(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'users',
            columns: ['id' => 'int'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'tasks',
            columns: ['id' => 'int', 'userId' => 'int'],
        ));
        // Declared via direct schema injection: addRelation would refuse
        // SET NULL on the non-nullable column, but legacy databases can
        // carry such an edge.
        $this->injectRelation(
            from: 'tasks',
            foreignKey: 'userId',
            to: 'users',
            references: 'id',
            type: 'belongsTo',
            onDelete: 'setNull',
        );

        $userId = $this->db->insert('users', []);
        $this->db->insert('tasks', ['userId' => $userId]);

        $usersBefore = file_get_contents(
            $this->dbDir . '/users/users.ndjson',
        );
        $tasksBefore = file_get_contents(
            $this->dbDir . '/tasks/tasks.ndjson',
        );

        try {
            $this->db->table('users')->deleteById($userId);
            Assert::fail('SET NULL cannot land on a non-nullable column');
        } catch (StorageException $e) {
            Assert::same(
                $e->getErrorKey(),
                'FOREIGN_KEY_SET_NULL_NOT_NULLABLE',
            );
        }

        Assert::same(
            file_get_contents($this->dbDir . '/users/users.ndjson'),
            $usersBefore,
        );
        Assert::same(
            file_get_contents($this->dbDir . '/tasks/tasks.ndjson'),
            $tasksBefore,
        );
    }

    #[Test]
    public function cascadeOnUpdateCompositeUniqueDuplicateAborts(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'vendors',
            uniqueConstraints: [
                new UniqueConstraint('u_code', ['code']),
            ],
            columns: ['id' => 'int', 'code' => 'string'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'items',
            uniqueConstraints: [
                new UniqueConstraint('u_pair', ['vendorCode', 'sku']),
            ],
            columns: [
                'id'         => 'int',
                'vendorCode' => 'string',
                'sku'        => 'string',
            ],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'items',
            foreignKey: 'vendorCode',
            toTable: 'vendors',
            references: 'code',
            type: RelationTypeEnum::BELONGS_TO,
            onUpdate: ForeignKeyActionEnum::CASCADE,
        ));

        $this->db->insert('vendors', ['code' => 'v1']);
        $this->db->insert('vendors', ['code' => 'v2']);
        $this->db->insert('items', ['vendorCode' => 'v1', 'sku' => 's']);
        $this->db->insert('items', ['vendorCode' => 'v2', 'sku' => 's']);

        $itemsBefore = file_get_contents(
            $this->dbDir . '/items/items.ndjson',
        );
        $vendorsBefore = file_get_contents(
            $this->dbDir . '/vendors/vendors.ndjson',
        );

        try {
            // Cascading v1 -> v2 would give two (v2, s) items.
            $this->db->update(
                'vendors',
                [new \AV\JsonProvider\Query\FilterCondition(
                    'code',
                    \AV\JsonProvider\Query\FilterOperatorEnum::EQ,
                    'v1',
                )],
                ['code' => 'v2'],
            );
            Assert::fail(
                'the cascaded patch collides on the composite unique key',
            );
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'UNIQUE_VIOLATION');
        }

        Assert::same(
            file_get_contents($this->dbDir . '/items/items.ndjson'),
            $itemsBefore,
            'the violation is found in the plan phase, disk untouched',
        );
        Assert::same(
            file_get_contents($this->dbDir . '/vendors/vendors.ndjson'),
            $vendorsBefore,
        );
    }

    #[Test]
    public function massSetNullDoesNotConflictWithItself(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'users',
            columns: ['id' => 'int'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'seats',
            uniqueConstraints: [
                new UniqueConstraint('u_user', ['userId']),
            ],
            columns: ['id' => 'int', 'userId' => 'int|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'seats',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::SET_NULL,
        ));

        $u1 = $this->db->insert('users', []);
        $u2 = $this->db->insert('users', []);
        $this->db->insert('seats', ['userId' => $u1]);
        $this->db->insert('seats', ['userId' => $u2]);

        $this->db->delete('users', []);

        $seats = $this->db->table('seats')->selectAllByArray();
        Assert::count(
            $seats,
            2,
            'two nulls in a unique column coexist (SQL NULL semantics)',
        );

        foreach ($seats as $seat) {
            Assert::same($seat['userId'], null);
        }
    }

    #[Test]
    public function setNullPropagationTriggersRestrictOfGrandchildren(): void
    {
        // a <- b.aRef (onDelete=setNull); d.x -> b.aRef (onUpdate=restrict):
        // nulling b.aRef is a value change the restrict grandchild edge
        // must see — otherwise the declared restrict is silently bypassed
        // and d.x dangles.
        $this->db->createTable(TableSchema::create(
            name: 'a',
            columns: ['id' => 'int'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'b',
            uniqueConstraints: [
                new UniqueConstraint('u_aref', ['aRef']),
            ],
            columns: ['id' => 'int', 'aRef' => 'int|null'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'd',
            columns: ['id' => 'int', 'x' => 'int|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'b',
            foreignKey: 'aRef',
            toTable: 'a',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::SET_NULL,
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'd',
            foreignKey: 'x',
            toTable: 'b',
            references: 'aRef',
            type: RelationTypeEnum::BELONGS_TO,
            onUpdate: ForeignKeyActionEnum::RESTRICT,
        ));

        $aId = $this->db->insert('a', []);
        $this->db->insert('b', ['aRef' => $aId]);
        $this->db->insert('d', ['x' => $aId]);

        try {
            $this->db->table('a')->deleteById($aId);
            Assert::fail(
                'the restrict grandchild references b.aRef and must '
                    . 'block the setNull chain',
            );
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'FOREIGN_KEY_RESTRICT');
        }

        Assert::same($this->db->table('a')->count(), 1);
        Assert::same(
            $this->db->table('b')->selectAllByArray()[0]['aRef'],
            $aId,
            'nothing may be written when the plan is refused',
        );
    }

    #[Test]
    public function setNullPropagationCascadesNullToGrandchildren(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'a',
            columns: ['id' => 'int'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'b',
            uniqueConstraints: [
                new UniqueConstraint('u_aref', ['aRef']),
            ],
            columns: ['id' => 'int', 'aRef' => 'int|null'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'c',
            columns: ['id' => 'int', 'x' => 'int|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'b',
            foreignKey: 'aRef',
            toTable: 'a',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::SET_NULL,
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'c',
            foreignKey: 'x',
            toTable: 'b',
            references: 'aRef',
            type: RelationTypeEnum::BELONGS_TO,
            onUpdate: ForeignKeyActionEnum::CASCADE,
        ));

        $aId = $this->db->insert('a', []);
        $this->db->insert('b', ['aRef' => $aId]);
        $this->db->insert('c', ['x' => $aId]);

        $this->db->table('a')->deleteById($aId);

        Assert::same(
            $this->db->table('b')->selectAllByArray()[0]['aRef'],
            null,
        );
        Assert::same(
            $this->db->table('c')->selectAllByArray()[0]['x'],
            null,
            'the grandchild must follow the nulled value, not dangle',
        );
    }

    #[Test]
    public function temporalCascadeOnUpdateKeepsTheStoredUtcForm(): void
    {
        date_default_timezone_set('Europe/Moscow');

        $this->db->createTable(TableSchema::create(
            name: 'events',
            uniqueConstraints: [
                new UniqueConstraint('u_dt', ['dt']),
            ],
            columns: ['id' => 'int', 'dt' => 'datetime'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'logs',
            columns: ['id' => 'int', 'eventDt' => 'datetime|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'logs',
            foreignKey: 'eventDt',
            toTable: 'events',
            references: 'dt',
            type: RelationTypeEnum::BELONGS_TO,
            onUpdate: ForeignKeyActionEnum::CASCADE,
        ));

        $this->db->insert('events', ['dt' => '2026-01-01 12:00:00']);
        $this->db->insert('logs', ['eventDt' => '2026-01-01 12:00:00']);

        $this->db->table('events')
            ->where('dt', '=', '2026-01-01 12:00:00')
            ->updateByArray(['dt' => '2026-06-01 15:30:00']);

        $events = $this->db->table('events')->selectAllByArray();
        $logs = $this->db->table('logs')->selectAllByArray();
        Assert::same(
            $logs[0]['eventDt'],
            $events[0]['dt'],
            'the cascaded temporal value must equal the parent value '
                . '(a re-encode would shift it a second time)',
        );
        Assert::same($logs[0]['eventDt'], '2026-06-01 15:30:00');
    }

    #[Test]
    public function unencodableChildAbortsThePreparePhaseUntouched(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'vendors',
            uniqueConstraints: [
                new UniqueConstraint('u_code', ['code']),
            ],
            columns: ['id' => 'int', 'code' => 'string'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'items',
            columns: [
                'id'         => 'int',
                'vendorCode' => 'string',
                'price'      => 'float',
            ],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'items',
            foreignKey: 'vendorCode',
            toTable: 'vendors',
            references: 'code',
            type: RelationTypeEnum::BELONGS_TO,
            onUpdate: ForeignKeyActionEnum::CASCADE,
        ));

        $this->db->insert('vendors', ['code' => 'v1']);
        $this->db->insert('items', [
            'vendorCode' => 'v1',
            'price'      => 10000.5,
        ]);

        // Same byte length keeps the byteSize gate green; json_decode
        // turns 1.0e999 into INF, which cannot be re-encoded.
        $itemsPath = $this->dbDir . '/items/items.ndjson';
        $tampered = str_replace(
            '"price":10000.5',
            '"price":1.0e999',
            (string)file_get_contents($itemsPath),
        );
        file_put_contents($itemsPath, $tampered);
        $this->db->invalidateCache('items');

        $vendorsBefore = file_get_contents(
            $this->dbDir . '/vendors/vendors.ndjson',
        );

        try {
            $this->db->update(
                'vendors',
                [new \AV\JsonProvider\Query\FilterCondition(
                    'code',
                    \AV\JsonProvider\Query\FilterOperatorEnum::EQ,
                    'v1',
                )],
                ['code' => 'v9'],
            );
            Assert::fail('the INF row cannot be re-encoded');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_RECORD');
        }

        Assert::same(
            file_get_contents($itemsPath),
            $tampered,
            'PREPARE failed for the child; its file must be untouched',
        );
        Assert::same(
            file_get_contents($this->dbDir . '/vendors/vendors.ndjson'),
            $vendorsBefore,
            'no table of the write set may be committed when one fails',
        );
    }

    // -- helpers -----------------------------------------------------------

    private function setUpParentWithTwoChildren(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'parents',
            columns: ['id' => 'int'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'cascadeKids',
            columns: ['id' => 'int', 'parentId' => 'int|null'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'restrictKids',
            columns: ['id' => 'int', 'parentId' => 'int|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'cascadeKids',
            foreignKey: 'parentId',
            toTable: 'parents',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'restrictKids',
            foreignKey: 'parentId',
            toTable: 'parents',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::RESTRICT,
        ));

        $parentId = $this->db->insert('parents', []);
        $this->db->insert('cascadeKids', ['parentId' => $parentId]);
        $this->db->insert('restrictKids', ['parentId' => $parentId]);
    }

    private function injectRelation(
        string $from,
        string $foreignKey,
        string $to,
        string $references,
        string $type,
        string | null $onDelete = null,
        string | null $onUpdate = null,
        string | null $backingIndex = null,
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

        if ($backingIndex !== null) {
            $entry['backingIndex'] = $backingIndex;
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
}
