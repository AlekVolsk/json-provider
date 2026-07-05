<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\TableSchema;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

/**
 * Unit tests for the primary-key contract enforced by TableSchema.
 *
 * Contract:
 *  - `columns` must contain field id of type int,
 *  - id is always at position 0,
 *  - constructor validates (throws on violation),
 *  - factory create() normalizes columns and indexes before validation.
 */
final class TableSchemaTest
{
    #[Test]
    public function constructorAcceptsValidColumns(): void
    {
        $schema = new TableSchema(
            name: 'products',
            columns: ['id' => 'int', 'title' => 'string'],
            indexes: [IndexSchema::primaryFor()],
        );

        Assert::same($schema->name, 'products');
        Assert::same($schema->columns, ['id' => 'int', 'title' => 'string']);
    }

    #[Test]
    public function constructorThrowsWhenIdMissing(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('mandatory PK column');

        new TableSchema(
            name: 'broken',
            columns: ['title' => 'string'],
            indexes: [IndexSchema::primaryFor()],
        );
    }

    #[Test]
    public function constructorThrowsWhenIdTypeIsNotInt(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('must have type "int"');

        new TableSchema(
            name: 'broken',
            columns: ['id' => 'string', 'title' => 'string'],
            indexes: [IndexSchema::primaryFor()],
        );
    }

    #[Test]
    public function constructorThrowsWhenIdIsNotFirst(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('must be first in columns');

        new TableSchema(
            name: 'broken',
            columns: ['title' => 'string', 'id' => 'int'],
            indexes: [IndexSchema::primaryFor()],
        );
    }

    #[Test]
    public function constructorThrowsWhenPkIndexMissing(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('mandatory PK index');

        new TableSchema(
            name: 'broken',
            columns: ['id' => 'int', 'title' => 'string'],
            indexes: [],
        );
    }

    #[Test]
    public function constructorThrowsWhenPkIndexIsNotFirst(): void
    {
        $other = new IndexSchema(
            name: 'idx_title',
            fields: [new IndexFieldSchema('title', SortDirectionEnum::ASC)],
        );

        Expect::exception(StorageException::class)
            ->withMessageContaining('PK index must be at position 0');

        new TableSchema(
            name: 'broken',
            columns: ['id' => 'int', 'title' => 'string'],
            indexes: [$other, IndexSchema::primaryFor()],
        );
    }

    #[Test]
    public function constructorThrowsWhenIndexNamedPkIsNotPrimary(): void
    {
        $fakePk = new IndexSchema(
            name: 'pk',
            fields: [new IndexFieldSchema('title', SortDirectionEnum::ASC)],
            isPrimary: false,
        );

        Expect::exception(StorageException::class)
            ->withMessageContaining('reserved');

        new TableSchema(
            name: 'broken',
            columns: ['id' => 'int', 'title' => 'string'],
            indexes: [$fakePk],
        );
    }

    #[Test]
    public function createAutoAddsPkIndexWhenMissing(): void
    {
        $schema = TableSchema::create(
            name: 'products',
            columns: ['id' => 'int', 'title' => 'string'],
            indexes: [],
        );

        Assert::iterable($schema->indexes)->notEmpty();
        Assert::true($schema->indexes[0]->isPrimary);
        Assert::same($schema->indexes[0]->name, 'pk');
        Assert::same($schema->indexes[0]->getFileName(), 'pk.index.ndjson');
    }

    #[Test]
    public function createKeepsExplicitPkIndexAtFront(): void
    {
        $explicit = IndexSchema::primaryFor();
        $other = new IndexSchema(
            name: 'idx_title',
            fields: [new IndexFieldSchema('title', SortDirectionEnum::ASC)],
        );

        $schema = TableSchema::create(
            name: 'products',
            columns: ['id' => 'int', 'title' => 'string'],
            indexes: [$explicit, $other],
        );

        Assert::count($schema->indexes, 2);
        Assert::same($schema->indexes[0], $explicit);
    }

    #[Test]
    public function createAddsIdWhenMissing(): void
    {
        $schema = TableSchema::create(
            name: 'products',
            columns: ['title' => 'string', 'price' => 'float'],
        );

        Assert::same(
            $schema->columns,
            ['id' => 'int', 'title' => 'string', 'price' => 'float'],
        );
    }

    #[Test]
    public function createMovesIdToFirstPosition(): void
    {
        $schema = TableSchema::create(
            name: 'products',
            columns: ['title' => 'string', 'id' => 'int', 'price' => 'float'],
        );

        Assert::same(array_key_first($schema->columns), 'id');
        Assert::same(
            $schema->columns,
            ['id' => 'int', 'title' => 'string', 'price' => 'float'],
        );
    }

    #[Test]
    public function createStillThrowsWhenIdTypeIsWrong(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('must have type "int"');

        TableSchema::create(
            name: 'broken',
            columns: ['id' => 'string', 'title' => 'string'],
        );
    }

    #[Test]
    public function createPreservesIdTypeIfAlreadyCorrect(): void
    {
        $schema = TableSchema::create(
            name: 'products',
            columns: ['id' => 'int', 'title' => 'string'],
        );

        Assert::same($schema->columns['id'], 'int');
    }

    #[Test]
    public function primaryKeyConstants(): void
    {
        Assert::same(PrimaryKey::FIELD, 'id');
        Assert::same(PrimaryKey::TYPE, 'int');
    }
}
