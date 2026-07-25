<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the closed column type set: every declared type must be one of
 * the 24 strings in ColumnTypes::all() (12 base types and their "|null"
 * variants). The check guards the TableSchema boundary, so it covers the
 * DDL API and loading a hand-edited information_schema.json alike — a typo
 * fails loudly instead of silently degrading the column to unvalidated
 * passthrough.
 */
final class ColumnTypeValidationTest
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
    public function unknownTypesRejected(): void
    {
        $bad = [
            'integer',
            'datetime|nullable',
            'variant',
            'STRING',
            'string |null',
            'string|null|null',
        ];

        foreach ($bad as $type) {
            try {
                $this->db->createTable(TableSchema::create(
                    name: 't',
                    columns: ['id' => 'int', 'x' => $type],
                ));
                Assert::fail('type must be rejected: ' . $type);
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'INVALID_COLUMN_TYPE', $type);
            }
        }

        Assert::false($this->db->hasTable('t'));
    }

    #[Test]
    public function allTwentyFourTypesPassTheConstructor(): void
    {
        $all = ColumnTypes::all();
        Assert::count($all, 24);

        $columns = ['id' => 'int'];

        foreach ($all as $i => $type) {
            $columns['c' . $i] = $type;
        }

        $schema = TableSchema::create(name: 'every_type', columns: $columns);

        Assert::count($schema->columns, 25);
    }

    #[Test]
    public function tamperedTypeInSchemaFileFailsOnNextAccess(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'goods',
            columns: ['id' => 'int', 'name' => 'string'],
        ));
        $this->db->insert('goods', ['name' => 'a']);

        $path = $this->dbDir . '/information_schema.json';
        $raw = (string)file_get_contents($path);
        $patched = str_replace('"name": "string"', '"name": "str"', $raw);

        if ($patched === $raw) {
            $patched = str_replace('"name":"string"', '"name":"str"', $raw);
        }

        Assert::true($patched !== $raw);
        file_put_contents($path, $patched);

        try {
            $this->db->table('goods')->selectAllByArray();
            Assert::fail('a tampered column type must fail the schema load');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_COLUMN_TYPE');
        }
    }

    #[Test]
    public function migrateWithInvalidNewColumnTypeFailsBeforeAnyWrite(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'm',
            columns: ['id' => 'int', 'a' => 'string'],
        ));
        $this->db->insert('m', ['a' => 'x']);

        $dataBefore = file_get_contents($this->dbDir . '/m/m.ndjson');

        try {
            $this->db->migrateColumns(TableSchema::create(
                name: 'm',
                columns: ['id' => 'int', 'a' => 'string', 'b' => 'text'],
            ));
            Assert::fail('an invalid new column type must be rejected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_COLUMN_TYPE');
        }

        Assert::same(
            file_get_contents($this->dbDir . '/m/m.ndjson'),
            $dataBefore,
        );
        Assert::same($this->db->columnNames('m'), ['id', 'a']);
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
        return TempDir::root('jp-coltype-tests');
    }
}
