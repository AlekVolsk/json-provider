<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\Fixture;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

/**
 * Tests for the physical record key order in NDJSON files.
 *
 * Contract:
 *  - key order in the file strictly follows columns order in the schema;
 *  - id is always first;
 *  - fields not in the schema are dropped;
 *  - a missing nullable schema field is added as null;
 *  - a missing non-nullable schema field is rejected (strict validation).
 *
 * Uses the shared fixture (products / categories / tags).
 */
final class RecordOrderTest
{
    #[Test]
    public function insertWritesKeysInSchemaOrderWithIdFirst(): void
    {
        $db = Fixture::db();

        $id = $db->table('categories')->insertByArray([
            'sort' => 999,
            'name' => 'OrderProbe',
        ]);

        $line = $this->readNdjsonLineByPredicate(
            'categories',
            static fn (array $r): bool => $r['id'] === $id,
        );
        Assert::notNull($line);

        Assert::same(array_keys($line), ['id', 'name', 'sort']);

        $db->table('categories')->where('id', '=', $id)->delete();
    }

    #[Test]
    public function insertDropsExtraFieldsNotInSchema(): void
    {
        $db = Fixture::db();

        $id = $db->table('categories')->insertByArray([
            'name'            => 'NoExtras',
            'sort'            => 7777,
            'unexpectedField' => 'should be dropped',
            'anotherExtra'    => 42,
        ]);

        $line = $this->readNdjsonLineByPredicate(
            'categories',
            static fn (array $r): bool => $r['id'] === $id,
        );
        Assert::notNull($line);

        Assert::same(array_keys($line), ['id', 'name', 'sort']);
        Assert::array($line)->doesNotHaveKeys('unexpectedField');
        Assert::array($line)->doesNotHaveKeys('anotherExtra');

        $db->table('categories')->where('id', '=', $id)->delete();
    }

    #[Test]
    public function insertFillsMissingNullableFieldWithNull(): void
    {
        $db = Fixture::db();
        $table = 'order_nullable_probe';

        $db->createTable(TableSchema::create(
            name: $table,
            columns: [
                'id'   => 'int',
                'name' => 'string',
                'note' => 'string|null',
            ],
        ));

        $id = $db->table($table)->insertByArray([
            'name' => 'MissingNullable',
        ]);

        $line = $this->readNdjsonLineByPredicate(
            $table,
            static fn (array $r): bool => $r['id'] === $id,
        );
        Assert::notNull($line);

        Assert::same(array_keys($line), ['id', 'name', 'note']);
        Assert::null($line['note']);
    }

    #[Test]
    public function insertRejectsMissingNonNullableField(): void
    {
        $db = Fixture::db();

        Expect::exception(StorageException::class)
            ->withMessageContaining('required column "sort"');

        $db->table('categories')->insertByArray([
            'name' => 'MissingSort',
        ]);
    }

    #[Test]
    public function updateKeepsKeyOrderInSchemaOrder(): void
    {
        $db = Fixture::db();

        $id = $db->table('categories')->insertByArray([
            'name' => 'BeforeUpdate',
            'sort' => 1,
        ]);

        $db->table('categories')->where('id', '=', $id)->updateByArray([
            'sort' => 2,
            'name' => 'AfterUpdate',
        ]);

        $line = $this->readNdjsonLineByPredicate(
            'categories',
            static fn (array $r): bool => $r['id'] === $id,
        );
        Assert::notNull($line);

        Assert::same(array_keys($line), ['id', 'name', 'sort']);
        Assert::same($line['name'], 'AfterUpdate');
        Assert::same($line['sort'], 2);

        $db->table('categories')->where('id', '=', $id)->delete();
    }

    /**
     * Reads the table NDJSON file directly and returns the first line for
     * which the predicate is true; null if no line matches.
     *
     * @param callable(array<string,null|scalar>): bool $predicate
     *
     * @return null|array<string,null|scalar>
     */
    private function readNdjsonLineByPredicate(
        string $tableName,
        callable $predicate,
    ): array | null {
        $path = Fixture::DB_PATH . '/' . $tableName
            . '/' . $tableName . '.ndjson';
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        foreach (explode("\n", $contents) as $rawLine) {
            $rawLine = trim($rawLine);

            if ($rawLine === '') {
                continue;
            }

            $decoded = json_decode($rawLine, true);

            if (!\is_array($decoded)) {
                continue;
            }

            /** @var array<string,null|scalar> $row */
            $row = $decoded;

            if ($predicate($row)) {
                return $row;
            }
        }

        return null;
    }
}
