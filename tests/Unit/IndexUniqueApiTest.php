<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the index/unique DDL API: addIndex builds the file from
 * current data before publishing the schema, dropIndex removes both, the
 * PK index is untouchable, addUniqueConstraint pre-checks existing data
 * with type-strict SQL-NULL keys and writes nothing on a duplicate, and
 * dropUniqueConstraint lifts enforcement.
 */
final class IndexUniqueApiTest
{
    private const string TABLE = 'goods';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: [
                'id'    => 'int',
                'name'  => 'string',
                'price' => 'float|null',
            ],
        ));

        foreach ([['b', 2.5], ['a', 1.5], ['c', null]] as [$name, $price]) {
            $this->db->insert(
                self::TABLE,
                ['name' => $name, 'price' => $price],
            );
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function addIndexBuildsFileAndServesSelects(): void
    {
        $this->db->addIndex(self::TABLE, $this->nameIndex());

        $file = $this->indexPath('idx_name');
        Assert::true(file_exists($file));
        Assert::count(
            array_filter(
                explode("\n", (string)file_get_contents($file)),
                static fn (string $line): bool => $line !== '',
            ),
            3,
        );

        Assert::same($this->schemaIndexNames(), ['pk', 'idx_name']);
        Assert::same($this->metaIndexFormat(), 2);

        $ordered = $this->db->select(
            self::TABLE,
            [],
            [new OrderBy('name', SortDirectionEnum::ASC)],
        );
        Assert::same(array_column($ordered, 'name'), ['a', 'b', 'c']);
    }

    #[Test]
    public function addIndexDuplicateNameRejected(): void
    {
        $this->db->addIndex(self::TABLE, $this->nameIndex());

        try {
            $this->db->addIndex(self::TABLE, $this->nameIndex());
            Assert::fail('a duplicate index name must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'IndexAlreadyExists');
        }
    }

    #[Test]
    public function addIndexUnknownColumnRejected(): void
    {
        try {
            $this->db->addIndex(self::TABLE, new IndexSchema(
                'idx_ghost',
                [new IndexFieldSchema('ghost', SortDirectionEnum::ASC)],
            ));
            Assert::fail('an index over an unknown column must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'IndexUnknownColumn');
        }

        Assert::false(file_exists($this->indexPath('idx_ghost')));
    }

    #[Test]
    public function addIndexPkNameRejected(): void
    {
        try {
            $this->db->addIndex(self::TABLE, new IndexSchema(
                'pk',
                [new IndexFieldSchema('id', SortDirectionEnum::ASC)],
                true,
            ));
            Assert::fail('the PK index must not be addable');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'PkIndexNotAddable');
        }
    }

    #[Test]
    public function dropIndexRemovesSchemaEntryAndFile(): void
    {
        $this->db->addIndex(self::TABLE, $this->nameIndex());
        Assert::true(file_exists($this->indexPath('idx_name')));

        $this->db->dropIndex(self::TABLE, 'idx_name');

        Assert::false(file_exists($this->indexPath('idx_name')));
        Assert::same($this->schemaIndexNames(), ['pk']);

        $ordered = $this->db->select(
            self::TABLE,
            [],
            [new OrderBy('name', SortDirectionEnum::ASC)],
        );
        Assert::same(array_column($ordered, 'name'), ['a', 'b', 'c']);
    }

    #[Test]
    public function dropIndexPkRejected(): void
    {
        try {
            $this->db->dropIndex(self::TABLE, 'pk');
            Assert::fail('the PK index must not be droppable');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'PkIndexNotDroppable');
        }
    }

    #[Test]
    public function dropIndexUnknownNameRejected(): void
    {
        try {
            $this->db->dropIndex(self::TABLE, 'idx_missing');
            Assert::fail('an unknown index name must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'IndexNotFound');
        }
    }

    #[Test]
    public function addUniqueOnCleanDataEnforcesFromThenOn(): void
    {
        $this->db->addUniqueConstraint(
            self::TABLE,
            new UniqueConstraint('uq_name', ['name']),
        );

        try {
            $this->db->insert(self::TABLE, ['name' => 'a', 'price' => null]);
            Assert::fail('the fresh constraint must reject a duplicate');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'UniqueViolation');
        }
    }

    #[Test]
    public function addUniqueOverDuplicateDataRejectedSchemaUnchanged(): void
    {
        $this->db->insert(self::TABLE, ['name' => 'a', 'price' => 7.5]);

        $schemaBefore = file_get_contents(
            $this->dbDir . '/information_schema.json',
        );

        try {
            $this->db->addUniqueConstraint(
                self::TABLE,
                new UniqueConstraint('uq_name', ['name']),
            );
            Assert::fail('stored duplicates must reject the new constraint');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'UniqueViolation');
        }

        Assert::same(
            file_get_contents($this->dbDir . '/information_schema.json'),
            $schemaBefore,
        );

        $this->db->insert(self::TABLE, ['name' => 'a', 'price' => null]);
        Assert::same($this->db->table(self::TABLE)->count(), 5);
    }

    #[Test]
    public function addUniqueTreatsNullsAsNonParticipating(): void
    {
        $this->db->addUniqueConstraint(
            self::TABLE,
            new UniqueConstraint('uq_price', ['price']),
        );

        $this->db->insert(self::TABLE, ['name' => 'd', 'price' => null]);

        try {
            $this->db->insert(self::TABLE, ['name' => 'e', 'price' => 1.5]);
            Assert::fail('a real duplicate must still be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'UniqueViolation');
        }
    }

    #[Test]
    public function addUniqueDuplicateNameRejected(): void
    {
        $this->db->addUniqueConstraint(
            self::TABLE,
            new UniqueConstraint('uq_name', ['name']),
        );

        try {
            $this->db->addUniqueConstraint(
                self::TABLE,
                new UniqueConstraint('uq_name', ['price']),
            );
            Assert::fail('a duplicate constraint name must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same(
                $e->getErrorKey(),
                'UniqueConstraintAlreadyExists',
            );
        }
    }

    #[Test]
    public function addUniqueUnknownFieldRejected(): void
    {
        try {
            $this->db->addUniqueConstraint(
                self::TABLE,
                new UniqueConstraint('uq_ghost', ['ghost']),
            );
            Assert::fail('an unknown constraint field must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'UniqueConstraintUnknownColumn');
        }
    }

    #[Test]
    public function dropUniqueLiftsEnforcement(): void
    {
        $this->db->addUniqueConstraint(
            self::TABLE,
            new UniqueConstraint('uq_name', ['name']),
        );
        $this->db->dropUniqueConstraint(self::TABLE, 'uq_name');

        $id = $this->db->insert(
            self::TABLE,
            ['name' => 'a', 'price' => null],
        );

        Assert::int($id)->greaterThan(0);
    }

    #[Test]
    public function dropUniqueUnknownNameRejected(): void
    {
        try {
            $this->db->dropUniqueConstraint(self::TABLE, 'uq_missing');
            Assert::fail('an unknown constraint name must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'UniqueConstraintNotFound');
        }
    }

    private function nameIndex(): IndexSchema
    {
        return new IndexSchema(
            'idx_name',
            [new IndexFieldSchema('name', SortDirectionEnum::ASC)],
        );
    }

    private function indexPath(string $indexName): string
    {
        return $this->dbDir . '/' . self::TABLE . '/' . $indexName
            . '.index.ndjson';
    }

    /**
     * @return list<string>
     */
    private function schemaIndexNames(): array
    {
        $path = $this->dbDir . '/information_schema.json';
        $data = json_decode((string)file_get_contents($path), true);
        \assert(\is_array($data) && \is_array($data['tables']));

        $table = $data['tables'][self::TABLE];
        \assert(\is_array($table) && \is_array($table['indexes']));

        $names = [];

        foreach ($table['indexes'] as $def) {
            \assert(\is_array($def) && \is_string($def['name']));
            $names[] = $def['name'];
        }

        return $names;
    }

    private function metaIndexFormat(): int
    {
        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        \assert(\is_array($meta) && \is_array($meta[self::TABLE]));

        $format = $meta[self::TABLE]['indexFormat'];
        \assert(\is_int($format));

        return $format;
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
        return TempDir::root('jp-ddlindex-tests');
    }
}
