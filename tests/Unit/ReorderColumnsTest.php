<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Tests\Support\Fixture;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

/**
 * Tests for JsonDataProvider::reorderColumns and the JsonTable wrapper.
 *
 * Contract:
 *  - schema columns are persisted in the new order;
 *  - existing NDJSON records are rewritten with keys in the new order;
 *  - id is always at position 0 (auto-prepended / auto-moved);
 *  - unknown / duplicate column names — exception;
 *  - missing columns (after id-normalization) — exception;
 *  - operation is meta on JsonTable: does not affect accumulated query state.
 */
final class ReorderColumnsTest
{
    #[Test]
    public function reorderPersistsNewOrderInSchema(): void
    {
        $db = Fixture::db();

        $db->table('categories')->reorderColumns(['sort', 'name']);

        $persisted = $this->readSchemaColumns('categories');
        Assert::same(array_keys($persisted), ['id', 'sort', 'name']);

        $db->table('categories')->reorderColumns(['name', 'sort']);
    }

    #[Test]
    public function reorderRewritesExistingRecordsInNewOrder(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $id = $tbl->insertByArray(['name' => 'ReorderProbe', 'sort' => 4242]);

        $tbl->reorderColumns(['sort', 'name']);

        $line = $this->readNdjsonLineByPredicate(
            'categories',
            static fn (array $r): bool => $r['id'] === $id,
        );
        Assert::notNull($line);
        Assert::same(array_keys($line), ['id', 'sort', 'name']);

        $tbl->where('id', '=', $id)->delete();

        $tbl->reorderColumns(['name', 'sort']);
    }

    #[Test]
    public function reorderAutoPrependsIdWhenMissing(): void
    {
        $db = Fixture::db();

        $db->table('categories')->reorderColumns(['sort', 'name']);

        $persisted = $this->readSchemaColumns('categories');
        Assert::same(array_key_first($persisted), 'id');

        $db->table('categories')->reorderColumns(['name', 'sort']);
    }

    #[Test]
    public function reorderAutoMovesIdToFirst(): void
    {
        $db = Fixture::db();

        $db->table('categories')->reorderColumns(['sort', 'id', 'name']);

        $persisted = $this->readSchemaColumns('categories');
        Assert::same(array_key_first($persisted), 'id');
        Assert::same(array_keys($persisted), ['id', 'sort', 'name']);

        $db->table('categories')->reorderColumns(['name', 'sort']);
    }

    #[Test]
    public function reorderThrowsOnUnknownColumn(): void
    {
        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('unknown column "ghost"');

        Fixture::db()
            ->table('categories')
            ->reorderColumns(['name', 'ghost', 'sort']);
    }

    #[Test]
    public function reorderThrowsOnDuplicateColumn(): void
    {
        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('duplicate column "name"');

        Fixture::db()
            ->table('categories')
            ->reorderColumns(['name', 'sort', 'name']);
    }

    #[Test]
    public function reorderThrowsOnIncompleteList(): void
    {
        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('missing column(s)');

        Fixture::db()->table('categories')->reorderColumns(['sort']);
    }

    #[Test]
    public function reorderDoesNotResetAccumulatedQueryState(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $tbl->where('sort', '=', 10);

        $tbl->reorderColumns(['sort', 'name']);

        Assert::same($tbl->count(), 1);

        $tbl->reorderColumns(['name', 'sort']);
    }

    /**
     * @return array<string,string>
     */
    private function readSchemaColumns(string $tableName): array
    {
        $storage = new JsonStorage(Fixture::dbPath());
        $schema = $storage->read('information_schema.json');

        /** @var array<string,array{columns:array<string,string>}> $tables */
        $tables = $schema['tables'];

        return $tables[$tableName]['columns'];
    }

    /**
     * @param callable(array<string,null|scalar>): bool $predicate
     *
     * @return null|array<string,null|scalar>
     */
    private function readNdjsonLineByPredicate(
        string $tableName,
        callable $predicate,
    ): array | null {
        $dir = Fixture::dbPath() . '/' . $tableName;
        $path = $dir . '/' . $tableName . '.ndjson';
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
