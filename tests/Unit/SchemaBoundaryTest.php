<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
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
 * The schema boundary rejects index and unique definitions that could not
 * work — empty field lists, fields outside the columns, repeated names —
 * before anything reaches information_schema.json, so a bad DDL call never
 * leaves a schema the strict loader refuses (which would lock every
 * operation on every table). Identifiers with a trailing newline are
 * rejected as well: without the D modifier a `$` anchor also matches before
 * a final newline.
 */
final class SchemaBoundaryTest
{
    private string $dbDir = '';

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->dbDir = self::root() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove(self::root());
    }

    #[Test]
    public function emptyFieldListsAreRejectedByTheValueObjects(): void
    {
        $this->expectErrorKey(
            'IndexFieldsEmpty',
            static fn () => new IndexSchema('ix', []),
        );
        $this->expectErrorKey(
            'UniqueConstraintFieldsEmpty',
            static fn () => new UniqueConstraint('u', []),
        );
    }

    #[Test]
    public function createTableRejectsUnknownColumnsAndRepeatedNames(): void
    {
        $this->expectErrorKey(
            'UniqueConstraintUnknownColumn',
            fn () => $this->db->createTable(TableSchema::create(
                name: 'a',
                columns: ['x' => 'int'],
                uniqueConstraints: [new UniqueConstraint('u', ['zzz'])],
            )),
        );
        $this->expectErrorKey(
            'IndexUnknownColumn',
            fn () => $this->db->createTable(TableSchema::create(
                name: 'b',
                columns: ['x' => 'int'],
                indexes: [self::index('ix', 'zzz')],
            )),
        );
        $this->expectErrorKey(
            'IndexAlreadyExists',
            fn () => $this->db->createTable(TableSchema::create(
                name: 'c',
                columns: ['x' => 'int', 'y' => 'int'],
                indexes: [self::index('ix', 'x'), self::index('ix', 'y')],
            )),
        );
        $this->expectErrorKey(
            'UniqueConstraintAlreadyExists',
            fn () => $this->db->createTable(TableSchema::create(
                name: 'd',
                columns: ['x' => 'int', 'y' => 'int'],
                uniqueConstraints: [
                    new UniqueConstraint('u', ['x']),
                    new UniqueConstraint('u', ['y']),
                ],
            )),
        );

        Assert::same($this->db->tableNames(), []);
    }

    #[Test]
    public function databaseStaysUsableAfterRejectedDefinitions(): void
    {
        try {
            $this->db->createTable(TableSchema::create(
                name: 'bad',
                columns: ['x' => 'int'],
                indexes: [self::index('ix', 'zzz')],
            ));
        } catch (JsonProviderException) {
        }

        $this->db->createTable(TableSchema::create(
            name: 'good',
            columns: ['x' => 'int'],
        ));
        $this->db->table('good')->insertByArray(['x' => 1]);

        Assert::same($this->db->table('good')->count(), 1);
        Assert::false($this->db->validate()->hasErrors());
        Assert::same(
            JsonDataProvider::getInstance($this->dbDir)->tableNames(),
            ['good'],
        );
    }

    #[Test]
    public function addIndexAndUniqueNameTheUnknownColumnCase(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 't',
            columns: ['x' => 'int'],
        ));

        $this->expectErrorKey(
            'IndexUnknownColumn',
            fn () => $this->db->addIndex('t', self::index('ix', 'zzz')),
        );
        $this->expectErrorKey(
            'UniqueConstraintUnknownColumn',
            fn () => $this->db->addUniqueConstraint(
                't',
                new UniqueConstraint('u', ['zzz']),
            ),
        );
    }

    #[Test]
    public function identifiersWithTrailingNewlineAreRejected(): void
    {
        $this->expectErrorKey(
            'InvalidTableName',
            fn () => $this->db->createTable(TableSchema::create(
                name: "users\n",
                columns: ['x' => 'int'],
            )),
        );
        $this->expectErrorKey(
            'InvalidColumnName',
            fn () => $this->db->createTable(TableSchema::create(
                name: 'users',
                columns: ["x\n" => 'int'],
            )),
        );
        $this->expectErrorKey(
            'InvalidIndexName',
            static fn () => new IndexSchema("ix\n", [
                new IndexFieldSchema('x', SortDirectionEnum::ASC),
            ]),
        );

        Assert::same($this->db->tableNames(), []);
    }

    private function expectErrorKey(string $key, callable $call): void
    {
        try {
            $call();
            Assert::fail('expected ' . $key);
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), $key);
        }
    }

    private static function index(string $name, string $field): IndexSchema
    {
        return new IndexSchema($name, [
            new IndexFieldSchema($field, SortDirectionEnum::ASC),
        ]);
    }

    private static function root(): string
    {
        return TempDir::root('jp-schema-boundary-tests');
    }
}
