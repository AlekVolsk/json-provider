<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\FkBackingPolicyEnum;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IssueCategoryEnum;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Tests\Support\EngineAccess;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * A composite user index led by the FK column as the backing index of a
 * probing relation (FkBackingPolicyEnum::LeadingColumn).
 *
 * Each place that picks or checks a backing index is covered on its own:
 * addRelation, dropIndex, repair pick one under the policy; the FK engine
 * and the validator accept an index led by the FK column whatever the
 * policy of the instance that reads it.
 */
final class CompositeFkBackingTest
{
    private const string OWNERS = 'owners';

    private const string ITEMS = 'items';

    private const string COMPOSITE = 'idx_owner_val';

    private const string SINGLE = 'idx_owner';

    private const string SERVICE = '_fk_ownerId';

    private string $root;

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-composite-fk-backing');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
        $this->db->createTable(TableSchema::create(
            name: self::OWNERS,
            columns: ['id' => ColumnTypes::INT, 'name' => ColumnTypes::STRING],
        ));
        $this->db->createTable(TableSchema::create(
            name: self::ITEMS,
            columns: [
                'id'      => ColumnTypes::INT,
                'ownerId' => ColumnTypes::INT,
                'val'     => ColumnTypes::INT,
            ],
            indexes: [new IndexSchema(self::COMPOSITE, [
                new IndexFieldSchema('ownerId', SortDirectionEnum::DESC),
                new IndexFieldSchema('val', SortDirectionEnum::ASC),
            ])],
        ));
        $owners = [];

        for ($id = 1; $id <= 20; $id++) {
            $owners[] = ['id' => $id, 'name' => 'owner' . $id];
        }

        $this->db->importRecords(self::OWNERS, $owners);
        $items = [];

        for ($id = 1; $id <= 200; $id++) {
            $items[] = ['id' => $id, 'ownerId' => $id % 10 + 1, 'val' => $id];
        }

        $this->db->importRecords(self::ITEMS, $items);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * Under LeadingColumn addRelation backs the relation with the
     * composite index and builds no service index.
     */
    #[Test]
    public function addRelationTakesLeadingIndexUnderPolicy(): void
    {
        $this->db->setFkBackingPolicy(FkBackingPolicyEnum::LeadingColumn);
        $this->db->addRelation($this->relation(ForeignKeyActionEnum::RESTRICT));

        Assert::same($this->backing(), self::COMPOSITE);
        Assert::same($this->serviceIndexes(), []);
    }

    /**
     * By default addRelation builds the service index, as before.
     */
    #[Test]
    public function addRelationBuildsServiceIndexByDefault(): void
    {
        $this->db->addRelation($this->relation(ForeignKeyActionEnum::RESTRICT));

        Assert::same($this->backing(), self::SERVICE);
        Assert::same($this->serviceIndexes(), [self::SERVICE]);
    }

    /**
     * An index on exactly the FK column wins over one led by it.
     */
    #[Test]
    public function singleColumnIndexIsPreferred(): void
    {
        $this->addSingleIndex();
        $this->db->setFkBackingPolicy(FkBackingPolicyEnum::LeadingColumn);
        $this->db->addRelation($this->relation(ForeignKeyActionEnum::RESTRICT));

        Assert::same($this->backing(), self::SINGLE);
    }

    /**
     * Restrict probes through the composite index refuse a referenced
     * parent and let an unreferenced one go — also on an instance with the
     * default policy, which reads a relation another instance backed.
     */
    #[Test]
    public function leadingBackingAnswersRestrictProbes(): void
    {
        $this->db->setFkBackingPolicy(FkBackingPolicyEnum::LeadingColumn);
        $this->db->addRelation($this->relation(ForeignKeyActionEnum::RESTRICT));

        foreach ([$this->db, $this->reopen()] as $db) {
            $this->assertRestricted($db, 3);
        }

        Assert::true($this->reopen()->table(self::OWNERS)->deleteById(15));
        Assert::true($this->reopen()->table(self::OWNERS)->deleteById(16));
    }

    /**
     * A cascade through the composite index deletes exactly the children
     * of the deleted parent.
     */
    #[Test]
    public function leadingBackingDrivesCascade(): void
    {
        $this->db->setFkBackingPolicy(FkBackingPolicyEnum::LeadingColumn);
        $this->db->addRelation($this->relation(ForeignKeyActionEnum::CASCADE));

        $this->reopen()->table(self::OWNERS)->deleteById(4);
        $this->reopen()->table(self::OWNERS)->deleteById(17);

        $db = $this->reopen();
        Assert::same(
            $db->table(self::ITEMS)->where('ownerId', '=', 4)->count(),
            0,
        );
        Assert::same($db->table(self::ITEMS)->count(), 180);
    }

    /**
     * validate() takes an index led by the FK column for a covering
     * backing.
     */
    #[Test]
    public function validateAcceptsLeadingBacking(): void
    {
        $this->db->setFkBackingPolicy(FkBackingPolicyEnum::LeadingColumn);
        $this->db->addRelation($this->relation(ForeignKeyActionEnum::RESTRICT));

        Assert::same(
            $this->reopen()->validate()->issuesByCategory(
                IssueCategoryEnum::FK_BACKING_INDEX_MISSING,
            ),
            [],
        );
    }

    /**
     * Dropping the backing index re-points the relation to another user
     * index under LeadingColumn; by default it builds the service index.
     */
    #[Test]
    public function dropIndexRepointsUnderPolicy(): void
    {
        $this->addSingleIndex();
        $this->db->addRelation($this->relation(ForeignKeyActionEnum::RESTRICT));
        Assert::same($this->backing(), self::SINGLE);

        $this->db->setFkBackingPolicy(FkBackingPolicyEnum::LeadingColumn);
        $this->db->dropIndex(self::ITEMS, self::SINGLE);

        Assert::same($this->backing(), self::COMPOSITE);
        Assert::same($this->serviceIndexes(), []);
        $this->assertRestricted($this->reopen(), 3);
    }

    /**
     * By default, dropping the backing index builds the service index even
     * when a composite index led by the FK column remains.
     */
    #[Test]
    public function dropIndexBuildsServiceIndexByDefault(): void
    {
        $this->addSingleIndex();
        $this->db->addRelation($this->relation(ForeignKeyActionEnum::RESTRICT));
        $this->db->dropIndex(self::ITEMS, self::SINGLE);

        Assert::same($this->backing(), self::SERVICE);
        Assert::same($this->serviceIndexes(), [self::SERVICE]);
    }

    /**
     * Dropping a composite backing with no other candidate builds the
     * service index, and the probes keep working.
     */
    #[Test]
    public function droppedLeadingBackingIsReplacedByServiceIndex(): void
    {
        $this->db->setFkBackingPolicy(FkBackingPolicyEnum::LeadingColumn);
        $this->db->addRelation($this->relation(ForeignKeyActionEnum::RESTRICT));
        $this->db->dropIndex(self::ITEMS, self::COMPOSITE);

        Assert::same($this->backing(), self::SERVICE);
        $this->assertRestricted($this->reopen(), 3);
    }

    /**
     * repair() backs a relation that has no backing with the composite
     * index under LeadingColumn, and with the service index by default.
     */
    #[Test]
    public function repairPicksBackingUnderPolicy(): void
    {
        foreach (
            [
                [FkBackingPolicyEnum::LeadingColumn, self::COMPOSITE],
                [FkBackingPolicyEnum::SingleColumn, self::SERVICE],
            ] as [$policy, $expected]
        ) {
            $this->injectBareRelation();
            $db = $this->reopen()->setFkBackingPolicy($policy);
            $db->repair();

            Assert::same($this->backing(), $expected);
            $this->assertRestricted($this->reopen(), 3);
            $db->dropRelation(self::ITEMS, 'ownerId', self::OWNERS);
        }
    }

    private function relation(ForeignKeyActionEnum $onDelete): RelationSchema
    {
        return new RelationSchema(
            fromTable: self::ITEMS,
            foreignKey: 'ownerId',
            toTable: self::OWNERS,
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: $onDelete,
        );
    }

    private function addSingleIndex(): void
    {
        $this->db->addIndex(self::ITEMS, new IndexSchema(self::SINGLE, [
            new IndexFieldSchema('ownerId', SortDirectionEnum::ASC),
        ]));
    }

    private function assertRestricted(JsonDataProvider $db, int $owner): void
    {
        try {
            $db->table(self::OWNERS)->deleteById($owner);
            Assert::fail('a referenced parent was deleted');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'ForeignKeyRestrict');
        }
    }

    /**
     * A relation recorded in the schema file without a backing index, as
     * a hand edit leaves it.
     */
    private function injectBareRelation(): void
    {
        $storage = new JsonStorage($this->dbDir);

        /** @var array{relations?: array<int,array<string,string>>} $schema */
        $schema = $storage->read('information_schema.json');
        $schema['relations'] = [[
            'from'       => self::ITEMS,
            'foreignKey' => 'ownerId',
            'to'         => self::OWNERS,
            'references' => 'id',
            'type'       => 'belongsTo',
            'onDelete'   => 'restrict',
        ]];
        $storage->write('information_schema.json', $schema);
    }

    private function backing(): string | null
    {
        $relations = array_values($this->registry()->getAllRelations());
        Assert::same(\count($relations), 1);

        return $relations[0]->backingIndex;
    }

    /**
     * @return array<int,string>
     */
    private function serviceIndexes(): array
    {
        $names = [];

        foreach ($this->registry()->getTable(self::ITEMS)->indexes as $index) {
            if ($index->isService) {
                $names[] = $index->name;
            }
        }

        return $names;
    }

    private function registry(): SchemaRegistry
    {
        $registry = EngineAccess::context($this->db)->schema;
        $registry->reload();

        return $registry;
    }

    /**
     * A fresh instance over the same directory, with the default policy.
     */
    private function reopen(): JsonDataProvider
    {
        (new \ReflectionProperty(JsonDataProvider::class, 'instances'))
            ->setValue(null, []);

        return JsonDataProvider::getInstance($this->dbDir);
    }
}
