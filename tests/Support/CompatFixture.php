<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

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

/**
 * Schema and seed shared by both sides of the compatibility tests.
 *
 * The class runs unchanged on the current engine and on the published
 * v1.0.0 one (LegacyRunner loads it next to the legacy sources), so it
 * sticks to the API both versions expose. The shape covers every on-disk
 * artifact a version could disagree on: a unique constraint with its own
 * index, a composite user index, a cascading relation with an engine-built
 * service backing index.
 */
final class CompatFixture
{
    public const string OWNERS = 'owners';
    public const string ITEMS = 'items';
    public const string EXTRAS = 'extras';

    public static function build(JsonDataProvider $db): void
    {
        $db->createTable(TableSchema::create(
            name: self::OWNERS,
            uniqueConstraints: [
                new UniqueConstraint('uq_owners_email', ['email']),
            ],
            columns: [
                'email' => ColumnTypes::STRING,
                'name'  => ColumnTypes::STRING,
            ],
            indexes: [
                new IndexSchema('idx_owners_email', [self::asc('email')]),
            ],
        ));

        $db->createTable(TableSchema::create(
            name: self::ITEMS,
            columns: [
                'ownerId' => ColumnTypes::INT,
                'title'   => ColumnTypes::STRING,
                'qty'     => ColumnTypes::INT,
            ],
            indexes: [
                new IndexSchema(
                    'idx_items_owner_title',
                    [self::asc('ownerId'), self::asc('title')],
                ),
            ],
        ));

        $db->addRelation(new RelationSchema(
            fromTable: self::ITEMS,
            foreignKey: 'ownerId',
            toTable: self::OWNERS,
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));
    }

    /**
     * Three owners with two items each.
     */
    public static function seed(JsonDataProvider $db): void
    {
        foreach (['ann', 'bob', 'cid'] as $name) {
            $ownerId = $db->table(self::OWNERS)->insertByArray([
                'email' => $name . '@example.test',
                'name'  => $name,
            ]);

            foreach ([1, 2] as $n) {
                $db->table(self::ITEMS)->insertByArray([
                    'ownerId' => $ownerId,
                    'title'   => $name . '-item-' . $n,
                    'qty'     => $n,
                ]);
            }
        }
    }

    public static function extraTable(): TableSchema
    {
        return TableSchema::create(
            name: self::EXTRAS,
            columns: ['label' => ColumnTypes::STRING],
        );
    }

    private static function asc(string $field): IndexFieldSchema
    {
        return new IndexFieldSchema($field, SortDirectionEnum::ASC);
    }
}
