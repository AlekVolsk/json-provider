<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for identifier validation at the schema boundary: table, column
 * and index names must match the whitelist pattern (letter/digit/underscore
 * start, letters/digits/underscores/hyphens, max 64 chars, no dots or path
 * separators), the "_fk_" index prefix and the "_pendingRename" table name
 * are reserved, and no crafted name can touch the filesystem outside the
 * database directory.
 */
final class IdentifierRulesTest
{
    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function invalidTableNamesRejectedAndFsUntouched(): void
    {
        $marker = \dirname($this->dbDir) . '/marker-' . basename($this->dbDir);
        file_put_contents($marker, 'sentinel');

        $bad = [
            '.',
            '..',
            'a/b',
            'a\b',
            'таблица',
            str_repeat('x', 65),
            '-lead',
            '',
            '_pendingRename',
        ];

        foreach ($bad as $name) {
            try {
                $this->db->createTable(TableSchema::create(
                    name: $name,
                    columns: ['id' => 'int'],
                ));
                Assert::fail('name must be rejected: ' . $name);
            } catch (StorageException $e) {
                Assert::same(
                    $e->getErrorKey(),
                    'INVALID_TABLE_NAME',
                    $name,
                );
            }
        }

        Assert::same(file_get_contents($marker), 'sentinel');
        Assert::same($this->db->tableNames(), []);
        unlink($marker);
    }

    #[Test]
    public function dropTableWithInvalidNameThrowsInsteadOfNoOp(): void
    {
        foreach (['..', 'a/b', 'плохо'] as $name) {
            try {
                $this->db->dropTable($name);
                Assert::fail('dropTable must reject: ' . $name);
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'INVALID_TABLE_NAME');
            }
        }
    }

    #[Test]
    public function invalidColumnNamesRejected(): void
    {
        foreach (['a.b', 'ко-лонка', 'a/b', str_repeat('c', 65)] as $column) {
            try {
                $this->db->createTable(TableSchema::create(
                    name: 't',
                    columns: ['id' => 'int', $column => 'string'],
                ));
                Assert::fail('column must be rejected: ' . $column);
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'INVALID_COLUMN_NAME');
            }
        }
    }

    #[Test]
    public function invalidIndexNamesRejectedInConstructor(): void
    {
        foreach (['.', '..', 'i/x', 'i\x', '-i', 'индекс'] as $name) {
            try {
                new IndexSchema(
                    $name,
                    [new IndexFieldSchema('id', SortDirectionEnum::ASC)],
                );
                Assert::fail('index name must be rejected: ' . $name);
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'INVALID_INDEX_NAME');
            }
        }
    }

    #[Test]
    public function serviceFkIndexPrefixIsReserved(): void
    {
        try {
            new IndexSchema(
                '_fk_user_id',
                [new IndexFieldSchema('user_id', SortDirectionEnum::ASC)],
            );
            Assert::fail('the _fk_ prefix must be reserved');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'RESERVED_INDEX_NAME');
        }
    }

    #[Test]
    public function purelyNumericNamesRejected(): void
    {
        try {
            $this->db->createTable(TableSchema::create(
                name: '7',
                columns: ['id' => 'int'],
            ));
            Assert::fail('a purely numeric table name must be rejected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_TABLE_NAME');
        }

        try {
            new IndexSchema(
                '123',
                [new IndexFieldSchema('id', SortDirectionEnum::ASC)],
            );
            Assert::fail('a purely numeric index name must be rejected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_INDEX_NAME');
        }
    }

    #[Test]
    public function numericColumnKeyInSchemaFileFailsOnLoad(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'good',
            columns: ['id' => 'int'],
        ));

        $path = $this->dbDir . '/information_schema.json';
        $data = json_decode((string)file_get_contents($path), true);
        \assert(\is_array($data) && \is_array($data['tables']));
        $good = $data['tables']['good'];
        \assert(\is_array($good) && \is_array($good['columns']));
        $good['columns']['0'] = 'string';
        $data['tables']['good'] = $good;
        unlink($path);
        file_put_contents($path, json_encode($data));

        try {
            $this->db->tableNames();
            Assert::fail('a numeric column key must fail the schema load');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_COLUMN_NAME');
        }
    }

    #[Test]
    public function validEdgeNamesPass(): void
    {
        $this->db->createTable(TableSchema::create(
            name: '0-day_Table',
            columns: ['id' => 'int', '_col-1' => 'string|null'],
        ));

        Assert::true($this->db->hasTable('0-day_Table'));

        $this->db->createTable(TableSchema::create(
            name: str_repeat('y', 64),
            columns: ['id' => 'int'],
        ));

        Assert::true($this->db->hasTable(str_repeat('y', 64)));
    }

    #[Test]
    public function tamperedSchemaWithTraversalTableKeyFailsOnLoad(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'good',
            columns: ['id' => 'int'],
        ));

        $path = $this->dbDir . '/information_schema.json';
        $data = json_decode((string)file_get_contents($path), true);
        \assert(\is_array($data) && \is_array($data['tables']));
        $data['tables']['..'] = $data['tables']['good'];
        file_put_contents($path, json_encode($data));

        try {
            $this->db->tableNames();
            Assert::fail('a traversal table key must fail the schema load');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_TABLE_NAME');
        }
    }

    #[Test]
    public function tamperedDataFileColumnKeyFailsOnRead(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'rows',
            columns: ['id' => 'int', 'v' => 'string'],
        ));
        $this->db->insert('rows', ['v' => 'a']);

        $path = $this->dbDir . '/rows/rows.ndjson';
        file_put_contents($path, '{"id":1,"bad name":"a"}' . "\n");
        $this->db->invalidateCache('rows');

        try {
            $this->db->table('rows')->selectAllByArray();
            Assert::fail('a tampered stored column key must fail loudly');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_COLUMN_NAME');
        }
    }

    #[Test]
    public function restoreOfForeignArchiveReportsSchemaMismatch(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'mine',
            columns: ['id' => 'int'],
        ));

        $otherDir = self::dbPathRoot() . '/' . uniqid('other', true);
        $other = JsonDataProvider::createDatabase($otherDir);
        $other->createTable(TableSchema::create(
            name: 'foreign_table',
            columns: ['id' => 'int'],
        ));

        $archive = self::dbPathRoot() . '/foreign-' . uniqid() . '.tar.gz';
        $other->backup($archive);

        try {
            $this->db->restore($archive);
            Assert::fail('a foreign archive must not restore');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'BACKUP_SCHEMA_MISMATCH');
        }
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
        return TempDir::root('jp-idrules-tests');
    }
}
