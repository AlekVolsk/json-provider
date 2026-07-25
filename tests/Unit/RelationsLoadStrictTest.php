<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for strict schema loading: a structurally broken entry in
 * information_schema.json (relations, indexes, unique constraints, table
 * definitions) raises a loud exception with the exact address of the
 * problem instead of being silently skipped — a dropped relation entry
 * would silently disable an FK the author believed was enforced.
 */
final class RelationsLoadStrictTest
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
            columns: ['id' => 'int', 'name' => 'string'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'orders',
            columns: ['id' => 'int', 'user_id' => 'int'],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function invalidRelationActionsRejected(): void
    {
        foreach (['CASCADE', 'set_null', 'SET NULL', 'restrict!'] as $action) {
            $this->withRelations([[
                'from'       => 'orders',
                'foreignKey' => 'user_id',
                'to'         => 'users',
                'references' => 'id',
                'type'       => 'belongsTo',
                'onDelete'   => $action,
            ]]);

            $this->expectLoadKey('RELATION_ACTION_INVALID', $action);
        }
    }

    #[Test]
    public function typoedRelationKeyRejected(): void
    {
        $this->withRelations([[
            'form'       => 'orders',
            'foreignKey' => 'user_id',
            'to'         => 'users',
            'references' => 'id',
            'type'       => 'belongsTo',
        ]]);

        $this->expectLoadKey('RELATION_ENTRY_INVALID', 'form typo');
    }

    #[Test]
    public function unknownRelationTypeRejected(): void
    {
        $this->withRelations([[
            'from'       => 'orders',
            'foreignKey' => 'user_id',
            'to'         => 'users',
            'references' => 'id',
            'type'       => 'ownedBy',
        ]]);

        $this->expectLoadKey('RELATION_ENTRY_INVALID', 'unknown type');
    }

    #[Test]
    public function missingActionsDefaultToNoAction(): void
    {
        $this->withRelations([[
            'from'       => 'orders',
            'foreignKey' => 'user_id',
            'to'         => 'users',
            'references' => 'id',
            'type'       => 'belongsTo',
        ]]);

        Assert::same($this->db->tableNames(), ['users', 'orders']);
    }

    #[Test]
    public function uppercaseIndexDirectionRejected(): void
    {
        $this->patchSchema(static function (array $data): array {
            \assert(\is_array($data['tables']));
            $users = $data['tables']['users'];
            \assert(\is_array($users) && \is_array($users['indexes']));
            $index = $users['indexes'][0];
            \assert(\is_array($index) && \is_array($index['fields']));
            $field = $index['fields'][0];
            \assert(\is_array($field));

            $field['direction'] = 'DESC';
            $index['fields'][0] = $field;
            $users['indexes'][0] = $index;
            $data['tables']['users'] = $users;

            return $data;
        });

        $this->expectLoadKey('INVALID_SCHEMA', 'DESC direction');
    }

    #[Test]
    public function uniqueWithoutFieldsRejected(): void
    {
        $this->patchSchema(static function (array $data): array {
            \assert(\is_array($data['tables']));
            $users = $data['tables']['users'];
            \assert(\is_array($users));

            $users['unique'] = [['name' => 'uq_broken']];
            $data['tables']['users'] = $users;

            return $data;
        });

        $this->expectLoadKey('INVALID_SCHEMA', 'unique without fields');
    }

    #[Test]
    public function nonArrayTableDefinitionRejected(): void
    {
        $this->patchSchema(static function (array $data): array {
            \assert(\is_array($data['tables']));
            $data['tables']['users'] = 'broken';

            return $data;
        });

        $this->expectLoadKey('INVALID_SCHEMA', 'table def not object');
    }

    #[Test]
    public function nonStringColumnTypeRejected(): void
    {
        $this->patchSchema(static function (array $data): array {
            \assert(\is_array($data['tables']));
            $users = $data['tables']['users'];
            \assert(\is_array($users) && \is_array($users['columns']));

            $users['columns']['name'] = 5;
            $data['tables']['users'] = $users;

            return $data;
        });

        $this->expectLoadKey('INVALID_SCHEMA', 'non-string column type');
    }

    #[Test]
    public function missingTablesKeyRejected(): void
    {
        $this->patchSchema(static function (array $data): array {
            unset($data['tables']);

            return $data;
        });

        $this->expectLoadKey('INVALID_SCHEMA', 'missing tables key');
    }

    #[Test]
    public function emptyTablesCollectionIsValid(): void
    {
        $emptyDir = self::dbPathRoot() . '/' . uniqid('empty', true);
        $empty = JsonDataProvider::createDatabase($emptyDir);

        Assert::same($empty->tableNames(), []);
    }

    #[Test]
    public function mutateRoundtripSurvivesStrictParse(): void
    {
        $this->withRelations([[
            'from'       => 'orders',
            'foreignKey' => 'user_id',
            'to'         => 'users',
            'references' => 'id',
            'type'       => 'belongsTo',
            'onDelete'   => 'cascade',
        ]]);

        $this->db->setTableComment('users', 'people');

        $raw = (string)file_get_contents(
            $this->dbDir . '/information_schema.json',
        );
        Assert::string($raw)->contains('cascade');
        Assert::same($this->db->getTableComment('users'), 'people');
        Assert::same($this->db->tableNames(), ['users', 'orders']);
    }

    /**
     * @param array<int,array<string,mixed>> $relations
     */
    private function withRelations(array $relations): void
    {
        $this->patchSchema(
            static function (array $data) use ($relations): array {
                $data['relations'] = $relations;

                return $data;
            },
        );
    }

    /**
     * Rewrites information_schema.json through the callback, recreating
     * the file so its inode changes and every registry re-reads it.
     *
     * @param callable(array<mixed>): array<mixed> $patch
     */
    private function patchSchema(callable $patch): void
    {
        $path = $this->dbDir . '/information_schema.json';
        $data = json_decode((string)file_get_contents($path), true);
        \assert(\is_array($data));

        $data = $patch($data);

        unlink($path);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function expectLoadKey(string $key, string $case): void
    {
        try {
            $this->db->tableNames();
            Assert::fail('schema load must fail: ' . $case);
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), $key, $case);
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
        return TempDir::root('jp-relstrict-tests');
    }
}
