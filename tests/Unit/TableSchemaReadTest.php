<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Services\Integrity\IssueCategoryEnum;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * getTableSchema() gives a table's schema as declared — the object
 * createTable() takes: columns, the primary key index, user indexes,
 * unique constraints, comments. The service indexes relations provision
 * stay out of it.
 */
final class TableSchemaReadTest
{
    private string $root;

    private string $dbDir;

    private JsonDataProvider $db;

    private TableSchema $owners;

    private TableSchema $items;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-table-schema-read');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
        $this->owners = TableSchema::create(
            name: 'owners',
            columns: ['name' => ColumnTypes::STRING],
        );
        $this->items = TableSchema::create(
            name: 'items',
            uniqueConstraints: [new UniqueConstraint('uq_code', ['code'])],
            columns: [
                'code'    => ColumnTypes::STRING,
                'ownerId' => ColumnTypes::INT,
                'note'    => ColumnTypes::STRING_NULLABLE,
            ],
            indexes: [new IndexSchema('idx_code_owner', [
                new IndexFieldSchema('code', SortDirectionEnum::ASC),
                new IndexFieldSchema('ownerId', SortDirectionEnum::DESC),
            ])],
            tableComment: 'Items',
            columnComment: ['code' => 'Item code'],
        );
        $this->db->createTable($this->owners);
        $this->db->createTable($this->items);
        $this->db->addRelation(self::relation());
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * The schema read back equals the one the table was created from,
     * though the table also holds the service index of its relation.
     */
    #[Test]
    public function schemaIsAsDeclared(): void
    {
        $backing = $this->db->relations('items')[0]->backingIndex;
        Assert::true(\is_string($backing) && str_starts_with($backing, '_fk_'));

        Assert::equals($this->db->getTableSchema('items'), $this->items);
        Assert::equals($this->db->getTableSchema('owners'), $this->owners);
    }

    /**
     * Tables created from the schemas read back, with the relations added
     * again, come out as the originals; no service index is left without
     * its relation in between.
     */
    #[Test]
    public function schemaRecreatesTable(): void
    {
        $copy = JsonDataProvider::createDatabase($this->root . '/copy');
        $copy->createTable($this->db->getTableSchema('owners'));
        $copy->createTable($this->db->getTableSchema('items'));

        Assert::same(
            $copy->validate()->issuesByCategory(
                IssueCategoryEnum::FK_BACKING_INDEX_ORPHANED,
            ),
            [],
        );

        $copy->addRelation(self::relation());

        Assert::equals(
            $copy->getTableSchema('items'),
            $this->db->getTableSchema('items'),
        );
        Assert::same(
            $copy->relations('items')[0]->backingIndex,
            $this->db->relations('items')[0]->backingIndex,
        );
    }

    /**
     * A user index a relation reuses as its backing is a declared index
     * and stays in the schema.
     */
    #[Test]
    public function reusedUserIndexStays(): void
    {
        $owned = new IndexSchema('idx_tags_owner', [
            new IndexFieldSchema('ownerId', SortDirectionEnum::ASC),
        ]);
        $this->db->createTable(TableSchema::create(
            name: 'tags',
            columns: ['ownerId' => ColumnTypes::INT],
            indexes: [$owned],
        ));
        $this->db->addRelation(self::relation('tags'));

        Assert::same(
            $this->db->relations('tags')[0]->backingIndex,
            'idx_tags_owner',
        );
        Assert::same(
            array_map(
                static fn (IndexSchema $i): string => $i->name,
                $this->db->getTableSchema('tags')->indexes,
            ),
            ['pk', 'idx_tags_owner'],
        );
    }

    /**
     * A schema change made by another process shows in the next read.
     */
    #[Test]
    public function schemaFollowsAnotherProcess(): void
    {
        $this->db->getTableSchema('items');
        $this->inChild(<<<'PHP'
            \AV\JsonProvider\JsonDataProvider::getInstance($argv[2])
                ->addIndex('items', new \AV\JsonProvider\Schema\IndexSchema(
                    'idx_note',
                    [new \AV\JsonProvider\Schema\IndexFieldSchema(
                        'note',
                        \AV\JsonProvider\Query\SortDirectionEnum::ASC,
                    )],
                ));
            PHP);

        Assert::same(
            array_map(
                static fn (IndexSchema $i): string => $i->name,
                $this->db->getTableSchema('items')->indexes,
            ),
            ['pk', 'idx_code_owner', 'idx_note'],
        );
    }

    /**
     * An unknown table is TableNotFound, for the read and for the diff.
     */
    #[Test]
    public function unknownTableIsNotFound(): void
    {
        $calls = [
            fn (): mixed => $this->db->getTableSchema('missing'),
            fn (): mixed => $this->db->diffTable(TableSchema::create(
                name: 'missing',
            )),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                Assert::fail('an unknown table was read');
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'TableNotFound');
            }
        }
    }

    private static function relation(string $from = 'items'): RelationSchema
    {
        return new RelationSchema(
            fromTable: $from,
            foreignKey: 'ownerId',
            toTable: 'owners',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        );
    }

    /**
     * Runs $code in another process with the autoloader in $argv[1] and
     * the database path in $argv[2].
     */
    private function inChild(string $code): void
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                'require $argv[1];' . "\n" . $code,
                '--',
                \dirname(__DIR__, 2) . '/vendor/autoload.php',
                $this->dbDir,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        Assert::true(\is_resource($process));
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        Assert::same(proc_close($process), 0, $stderr);
    }
}
