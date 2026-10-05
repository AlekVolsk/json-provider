<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableDiff;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * diffTable() reports what separates a table from a desired schema —
 * columns, indexes and unique constraints to add, drop or change — and
 * changes nothing. The primary key index and the service indexes of
 * relations are not reported: the engine owns them.
 */
final class TableDiffTest
{
    private const string TABLE = 'items';

    private string $root;

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-table-diff');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
        $this->db->createTable(TableSchema::create(
            name: 'owners',
            columns: ['name' => ColumnTypes::STRING],
        ));
        $this->db->createTable(self::schema());
        $this->db->addRelation(new RelationSchema(
            fromTable: self::TABLE,
            foreignKey: 'ownerId',
            toTable: 'owners',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));
        $this->db->insert('owners', ['name' => 'o']);
        $this->db->insert(self::TABLE, [
            'name'    => 'a',
            'qty'     => 1,
            'note'    => null,
            'code'    => 'c1',
            'ownerId' => 1,
        ]);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * The schema the table was created from, and the one read back, match
     * the table, though it also holds the service index of its relation.
     */
    #[Test]
    public function sameSchemaHasNoDifference(): void
    {
        Assert::true($this->db->diffTable(self::schema())->isEmpty());
        Assert::true(
            $this->db->diffTable($this->db->getTableSchema(self::TABLE))
                ->isEmpty(),
        );
    }

    /**
     * Added, dropped and retyped columns are reported apart from a change
     * of order: a column added in the middle moves nothing.
     */
    #[Test]
    public function columnsDiffer(): void
    {
        $diff = $this->db->diffTable(self::schema(columns: [
            'name'    => ColumnTypes::STRING,
            'phone'   => ColumnTypes::STRING_NULLABLE,
            'qty'     => ColumnTypes::INT_NULLABLE,
            'code'    => ColumnTypes::STRING,
            'ownerId' => ColumnTypes::INT,
        ], indexes: [], unique: []));

        Assert::same($diff->table, self::TABLE);
        Assert::same(
            $diff->addedColumns,
            ['phone' => ColumnTypes::STRING_NULLABLE],
        );
        Assert::same(
            $diff->droppedColumns,
            ['note' => ColumnTypes::STRING_NULLABLE],
        );
        Assert::same($diff->retypedColumns, ['qty' => [
            'current' => ColumnTypes::INT,
            'desired' => ColumnTypes::INT_NULLABLE,
        ]]);
        Assert::false($diff->columnOrderChanged);

        $swapped = $this->db->diffTable(self::schema(columns: [
            'qty'     => ColumnTypes::INT,
            'name'    => ColumnTypes::STRING,
            'note'    => ColumnTypes::STRING_NULLABLE,
            'code'    => ColumnTypes::STRING,
            'ownerId' => ColumnTypes::INT,
        ]));

        Assert::true($swapped->columnOrderChanged);
        Assert::same($swapped->addedColumns, []);
        Assert::same($swapped->droppedColumns, []);
        Assert::same($swapped->retypedColumns, []);
        Assert::false($swapped->isEmpty());
    }

    /**
     * Indexes are matched by name regardless of case and differ by name,
     * fields or directions; a desired service index is not the caller's
     * to add.
     */
    #[Test]
    public function indexesDiffer(): void
    {
        $diff = $this->db->diffTable(self::schema(indexes: [
            self::index('idx_name', ['name' => SortDirectionEnum::DESC]),
            self::index('idx_qty', ['qty' => SortDirectionEnum::ASC]),
            self::index('idx_code', ['code' => SortDirectionEnum::ASC]),
            self::index('idx_pair', [
                'qty'  => SortDirectionEnum::ASC,
                'name' => SortDirectionEnum::ASC,
            ]),
            self::index('idx_new', ['note' => SortDirectionEnum::ASC]),
            new IndexSchema('_fk_note', [
                new IndexFieldSchema('note', SortDirectionEnum::ASC),
            ], isService: true),
        ]));

        Assert::same(self::names($diff->addedIndexes), ['idx_new']);
        Assert::same(self::names($diff->droppedIndexes), ['idx_note']);
        $changed = [];

        foreach ($diff->changedIndexes as $change) {
            $changed[] = [$change['current']->name, $change['desired']->name];
        }

        Assert::same(
            $changed,
            [
                ['idx_name', 'idx_name'],
                ['idx_Code', 'idx_code'],
                ['idx_pair', 'idx_pair'],
            ],
        );
        Assert::same($diff->addedColumns, []);
        Assert::same($diff->addedUniqueConstraints, []);
        Assert::same($diff->droppedUniqueConstraints, []);
        Assert::same($diff->changedUniqueConstraints, []);
    }

    /**
     * Unique constraints are matched by name and differ by their set of
     * fields, not by its order.
     */
    #[Test]
    public function uniqueConstraintsDiffer(): void
    {
        $diff = $this->db->diffTable(self::schema(unique: [
            new UniqueConstraint('uq_code', ['code', 'ownerId']),
            new UniqueConstraint('uq_pair', ['qty', 'name']),
            new UniqueConstraint('uq_new', ['name']),
        ]));

        Assert::same(
            array_map(
                static fn (UniqueConstraint $u): string => $u->name,
                $diff->addedUniqueConstraints,
            ),
            ['uq_new'],
        );
        Assert::same(
            array_map(
                static fn (UniqueConstraint $u): string => $u->name,
                $diff->droppedUniqueConstraints,
            ),
            ['uq_note'],
        );
        Assert::same(\count($diff->changedUniqueConstraints), 1);
        Assert::same(
            $diff->changedUniqueConstraints[0]['current']->fields,
            ['code'],
        );
        Assert::same(
            $diff->changedUniqueConstraints[0]['desired']->fields,
            ['code', 'ownerId'],
        );
        Assert::same($diff->addedIndexes, []);
        Assert::same($diff->droppedIndexes, []);
        Assert::same($diff->changedIndexes, []);
    }

    /**
     * A diff writes nothing: every file of the database keeps its bytes
     * and its modification time.
     */
    #[Test]
    public function diffChangesNothing(): void
    {
        $before = $this->files();
        $this->db->diffTable(self::schema(
            columns: ['name' => ColumnTypes::INT],
            indexes: [],
            unique: [],
        ));

        Assert::same($this->files(), $before);
    }

    /**
     * Applying what a diff reports, through the schema API, leaves no
     * difference.
     */
    #[Test]
    public function appliedDifferenceIsEmpty(): void
    {
        $desired = self::schema(
            columns: [
                'name'    => ColumnTypes::STRING,
                'phone'   => ColumnTypes::STRING_NULLABLE,
                'qty'     => ColumnTypes::INT,
                'code'    => ColumnTypes::STRING,
                'ownerId' => ColumnTypes::INT,
            ],
            indexes: [
                self::index('idx_name', ['name' => SortDirectionEnum::DESC]),
                self::index('idx_phone', ['phone' => SortDirectionEnum::ASC]),
            ],
            unique: [new UniqueConstraint('uq_code', ['code', 'ownerId'])],
        );
        $diff = $this->db->diffTable($desired);
        Assert::false($diff->isEmpty());

        foreach ($diff->droppedIndexes as $index) {
            $this->db->dropIndex(self::TABLE, $index->name);
        }

        foreach ($diff->changedIndexes as $change) {
            $this->db->dropIndex(self::TABLE, $change['current']->name);
        }

        foreach ($diff->droppedUniqueConstraints as $constraint) {
            $this->db->dropUniqueConstraint(self::TABLE, $constraint->name);
        }

        foreach ($diff->changedUniqueConstraints as $change) {
            $this->db->dropUniqueConstraint(
                self::TABLE,
                $change['current']->name,
            );
        }

        $this->db->migrateColumns($desired);

        foreach ($diff->addedIndexes as $index) {
            $this->db->addIndex(self::TABLE, $index);
        }

        foreach ($diff->changedIndexes as $change) {
            $this->db->addIndex(self::TABLE, $change['desired']);
        }

        foreach ($diff->addedUniqueConstraints as $constraint) {
            $this->db->addUniqueConstraint(self::TABLE, $constraint);
        }

        foreach ($diff->changedUniqueConstraints as $change) {
            $this->db->addUniqueConstraint(self::TABLE, $change['desired']);
        }

        Assert::true($this->db->diffTable($desired)->isEmpty());
    }

    /**
     * A pure comparison leaves out the primary key index on both sides,
     * and a service index of the current schema is not reported dropped.
     */
    #[Test]
    public function engineIndexesAreLeftOut(): void
    {
        $service = new IndexSchema('_fk_qty', [
            new IndexFieldSchema('qty', SortDirectionEnum::ASC),
        ], isService: true);
        $current = self::schema(indexes: [$service]);

        Assert::true(
            TableDiff::between($current, self::schema(indexes: []))
                ->isEmpty(),
        );
    }

    /**
     * @param null|array<string,string>        $columns
     * @param null|array<int,IndexSchema>      $indexes
     * @param null|array<int,UniqueConstraint> $unique
     */
    private static function schema(
        array | null $columns = null,
        array | null $indexes = null,
        array | null $unique = null,
    ): TableSchema {
        return TableSchema::create(
            name: self::TABLE,
            uniqueConstraints: $unique ?? [
                new UniqueConstraint('uq_code', ['code']),
                new UniqueConstraint('uq_pair', ['name', 'qty']),
                new UniqueConstraint('uq_note', ['note']),
            ],
            columns: $columns ?? [
                'name'    => ColumnTypes::STRING,
                'qty'     => ColumnTypes::INT,
                'note'    => ColumnTypes::STRING_NULLABLE,
                'code'    => ColumnTypes::STRING,
                'ownerId' => ColumnTypes::INT,
            ],
            indexes: $indexes ?? [
                self::index('idx_name', ['name' => SortDirectionEnum::ASC]),
                self::index('idx_qty', ['qty' => SortDirectionEnum::ASC]),
                self::index('idx_note', ['note' => SortDirectionEnum::ASC]),
                self::index('idx_Code', ['code' => SortDirectionEnum::ASC]),
                self::index('idx_pair', [
                    'name' => SortDirectionEnum::ASC,
                    'qty'  => SortDirectionEnum::ASC,
                ]),
            ],
        );
    }

    /**
     * @param array<string,SortDirectionEnum> $fields
     */
    private static function index(string $name, array $fields): IndexSchema
    {
        $parts = [];

        foreach ($fields as $field => $direction) {
            $parts[] = new IndexFieldSchema($field, $direction);
        }

        return new IndexSchema($name, $parts);
    }

    /**
     * @param array<int,IndexSchema> $indexes
     *
     * @return array<int,string>
     */
    private static function names(array $indexes): array
    {
        return array_map(
            static fn (IndexSchema $i): string => $i->name,
            $indexes,
        );
    }

    /**
     * Every file under the database directory with its bytes and mtime.
     *
     * @return array<string,array{string,int}>
     */
    private function files(): array
    {
        clearstatcache();
        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->dbDir,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($walk as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $path = $file->getPathname();
                $files[$path] = [
                    md5((string)file_get_contents($path)),
                    (int)$file->getMTime(),
                ];
            }
        }

        ksort($files);

        return $files;
    }
}
