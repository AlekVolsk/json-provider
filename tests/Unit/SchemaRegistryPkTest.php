<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the PK contract enforced by SchemaRegistry when reading a stored
 * information_schema.json.
 *
 * Read path is strict: any deviation from the PK contract (missing id, wrong
 * id type, id not at position 0, missing PK index) — JsonProviderException.
 *
 * Tests use an isolated DB path, separate from the main integration fixture.
 */
final class SchemaRegistryPkTest
{
    #[BeforeTest]
    public function setUp(): void
    {
        if (is_dir(self::dbPathRoot())) {
            $this->dropDatabase();
        }

        mkdir(self::dbPathRoot(), 0755, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->dropDatabase();
    }

    #[Test]
    public function parseTablesThrowsWhenStoredSchemaHasNoIdColumn(): void
    {
        $this->writeSchema([
            'tables' => [
                'broken' => [
                    'columns' => ['title' => 'string'],
                    'unique'  => [],
                    'indexes' => [],
                ],
            ],
            'relations' => [],
        ]);

        $registry = new SchemaRegistry(new JsonStorage(self::dbPathRoot()));

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('mandatory primary key column');

        $registry->getTables();
    }

    #[Test]
    public function parseTablesThrowsWhenIdHasWrongType(): void
    {
        $this->writeSchema([
            'tables' => [
                'broken' => [
                    'columns' => ['id' => 'string', 'title' => 'string'],
                    'unique'  => [],
                    'indexes' => [],
                ],
            ],
            'relations' => [],
        ]);

        $registry = new SchemaRegistry(new JsonStorage(self::dbPathRoot()));

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('must have type "int"');

        $registry->getTables();
    }

    #[Test]
    public function parseTablesThrowsWhenIdIsNotFirst(): void
    {
        $this->writeSchema([
            'tables' => [
                'broken' => [
                    'columns' => ['title' => 'string', 'id' => 'int'],
                    'unique'  => [],
                    'indexes' => [],
                ],
            ],
            'relations' => [],
        ]);

        $registry = new SchemaRegistry(new JsonStorage(self::dbPathRoot()));

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('must come first in the column list');

        $registry->getTables();
    }

    #[Test]
    public function parseTablesAcceptsValidSchema(): void
    {
        $this->writeSchema([
            'tables' => [
                'products' => [
                    'columns' => ['id' => 'int', 'title' => 'string'],
                    'unique'  => [],
                    'indexes' => [
                        [
                            'name'   => 'pk',
                            'fields' => [
                                ['field' => 'id', 'direction' => 'asc'],
                            ],
                            'isPrimary' => true,
                        ],
                    ],
                ],
            ],
            'relations' => [],
        ]);

        $registry = new SchemaRegistry(new JsonStorage(self::dbPathRoot()));
        $tables = $registry->getTables();

        Assert::count($tables, 1);
        Assert::same(
            $tables['products']->columns,
            ['id' => 'int', 'title' => 'string'],
        );
        Assert::true($tables['products']->indexes[0]->isPrimary);
    }

    #[Test]
    public function parseTablesThrowsWhenStoredSchemaHasNoPkIndex(): void
    {
        $this->writeSchema([
            'tables' => [
                'broken' => [
                    'columns' => ['id' => 'int', 'title' => 'string'],
                    'unique'  => [],
                    'indexes' => [],
                ],
            ],
            'relations' => [],
        ]);

        $registry = new SchemaRegistry(new JsonStorage(self::dbPathRoot()));

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('mandatory primary key index');

        $registry->getTables();
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeSchema(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \RuntimeException('failed to serialize test schema');
        }

        file_put_contents(
            self::dbPathRoot() . '/information_schema.json',
            $json,
        );
    }

    private function dropDatabase(): void
    {
        if (!is_dir(self::dbPathRoot())) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                self::dbPathRoot(),
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

        rmdir(self::dbPathRoot());
    }

    private static function dbPathRoot(): string
    {
        return TempDir::root('test_json_db_pk');
    }
}
