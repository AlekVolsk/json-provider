<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Tests\Support\Dto\RenameItemDto;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for truncate (SQL semantics: data gone, indexes rebuilt empty,
 * auto-increment reset to 0) and renameColumn (schema, data, indexes,
 * unique constraints, comments, relations and the DTO binding all follow
 * the rename; the PK column and taken names are rejected).
 */
final class TruncateRenameColumnTest
{
    private const string DB_PATH = '/tmp/jp-truncrename-tests';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: 'rn_items',
            columns: [
                'id'    => 'int',
                'title' => 'string',
                'qty'   => 'int',
            ],
            uniqueConstraints: [new UniqueConstraint('uq_qty', ['qty'])],
            indexes: [new IndexSchema(
                'idx_qty',
                [new IndexFieldSchema('qty', SortDirectionEnum::ASC)],
            )],
            columnComment: ['qty' => 'stock counter'],
        ));

        foreach ([['a', 3], ['b', 1], ['c', 2]] as [$title, $qty]) {
            $this->db->insert(
                'rn_items',
                ['title' => $title, 'qty' => $qty],
            );
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    // -- truncate ------------------------------------------------------------

    #[Test]
    public function truncateEmptiesDataResetsCounterRebuildsIndexes(): void
    {
        $this->db->truncate('rn_items');

        Assert::same($this->db->table('rn_items')->count(), 0);
        Assert::same(
            file_get_contents($this->dbDir . '/rn_items/rn_items.ndjson'),
            '',
        );
        Assert::same(
            file_get_contents(
                $this->dbDir . '/rn_items/idx_qty.index.ndjson',
            ),
            '',
        );

        $entry = $this->metaEntry('rn_items');
        Assert::same($entry['lineCount'], 0);
        Assert::same($entry['lastInsertedId'], 0);

        $id = $this->db->insert(
            'rn_items',
            ['title' => 'fresh', 'qty' => 9],
        );
        Assert::same($id, 1);
    }

    #[Test]
    public function truncateSelfHealsAMissingMetaEntry(): void
    {
        $metaPath = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($metaPath), true);
        \assert(\is_array($meta));
        unset($meta['rn_items']);
        unlink($metaPath);
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT));

        $this->db->truncate('rn_items');

        Assert::same($this->db->table('rn_items')->count(), 0);
        Assert::same(
            $this->db->insert(
                'rn_items',
                ['title' => 'fresh', 'qty' => 9],
            ),
            1,
        );
    }

    #[Test]
    public function truncateUnknownTableThrows(): void
    {
        try {
            $this->db->truncate('missing');
            Assert::fail('truncate of an unknown table must throw');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'TABLE_NOT_FOUND');
        }
    }

    // -- renameColumn ------------------------------------------------------

    #[Test]
    public function renameCarriesDataSchemaStructuresAndComments(): void
    {
        $this->db->renameColumn('rn_items', 'qty', 'amount');

        Assert::same(
            $this->db->columnNames('rn_items'),
            ['id', 'title', 'amount'],
        );

        $rows = $this->db->table('rn_items')->selectAllByArray();
        Assert::same(array_column($rows, 'amount'), [3, 1, 2]);

        Assert::same(
            $this->db->getColumnComment('rn_items', 'amount'),
            'stock counter',
        );
        Assert::null($this->db->getColumnComment('rn_items', 'qty'));

        $schema = $this->schemaTable('rn_items');
        \assert(\is_array($schema['indexes']));
        $index = $schema['indexes'][1];
        \assert(\is_array($index) && \is_array($index['fields']));
        $field = $index['fields'][0];
        \assert(\is_array($field));
        Assert::same($field['field'], 'amount');

        \assert(\is_array($schema['unique']));
        $unique = $schema['unique'][0];
        \assert(\is_array($unique));
        Assert::same($unique['fields'], ['amount']);

        // The renamed unique constraint still enforces.
        try {
            $this->db->insert(
                'rn_items',
                ['title' => 'x', 'amount' => 3],
            );
            Assert::fail('the renamed constraint must still enforce');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'UNIQUE_VIOLATION');
        }

        // The renamed index still serves ordered selects.
        $ordered = $this->db->table('rn_items')
            ->orderBy('amount', 'asc')->selectAllByArray();
        Assert::same(array_column($ordered, 'amount'), [1, 2, 3]);
    }

    #[Test]
    public function renameUpdatesRelationSides(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'rn_orders',
            columns: ['id' => 'int', 'item_qty' => 'int'],
        ));
        $this->injectRelation([
            'from'       => 'rn_orders',
            'foreignKey' => 'item_qty',
            'to'         => 'rn_items',
            'references' => 'qty',
            'type'       => 'belongsTo',
            'onDelete'   => 'cascade',
        ]);

        // Parent side: rn_items.qty is the referenced column.
        $this->db->renameColumn('rn_items', 'qty', 'amount');
        $relation = $this->schemaRelations()[0];
        Assert::same($relation['references'], 'amount');
        Assert::same($relation['foreignKey'], 'item_qty');

        // Child side: rn_orders.item_qty is the FK column.
        $this->db->renameColumn('rn_orders', 'item_qty', 'item_amount');
        $relation = $this->schemaRelations()[0];
        Assert::same($relation['foreignKey'], 'item_amount');
        Assert::same($relation['onDelete'], 'cascade');
    }

    #[Test]
    public function renameRecompilesDtoBinding(): void
    {
        $this->db->registerDto(RenameItemDto::class);

        // Renaming a column the DTO does not reference keeps hydration
        // working.
        $this->db->renameColumn('rn_items', 'qty', 'amount');

        $dto = $this->db->table('rn_items')->where('id', '=', 1)->selectOne();
        \assert($dto instanceof RenameItemDto);
        Assert::same($dto->title, 'a');

        // Renaming a referenced column drops the stale binding: object
        // reads fail loudly instead of hydrating garbage.
        $this->db->renameColumn('rn_items', 'title', 'caption');

        try {
            $this->db->table('rn_items')->where('id', '=', 1)->selectOne();
            Assert::fail('the stale DTO binding must be dropped');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'DTO_NOT_REGISTERED');
        }
    }

    #[Test]
    public function renameGuardsRejectBadArguments(): void
    {
        try {
            $this->db->renameColumn('rn_items', 'id', 'ident');
            Assert::fail('the PK column must not be renameable');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'PK_CONTRACT_VIOLATED');
        }

        try {
            $this->db->renameColumn('rn_items', 'qty', 'title');
            Assert::fail('a taken target name must be rejected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'COLUMN_ALREADY_EXISTS');
        }

        try {
            $this->db->renameColumn('rn_items', 'ghost', 'x');
            Assert::fail('an unknown source column must be rejected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'COLUMN_NOT_FOUND');
        }

        try {
            $this->db->renameColumn('rn_items', 'qty', 'плохое имя');
            Assert::fail('an invalid target name must be rejected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_COLUMN_NAME');
        }

        Assert::same(
            $this->db->columnNames('rn_items'),
            ['id', 'title', 'qty'],
        );
    }

    // -- helpers -----------------------------------------------------------

    /**
     * @return array<mixed>
     */
    private function metaEntry(string $table): array
    {
        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        \assert(\is_array($meta) && \is_array($meta[$table]));

        return $meta[$table];
    }

    /**
     * @return array<mixed>
     */
    private function schemaTable(string $table): array
    {
        $data = json_decode(
            (string)file_get_contents(
                $this->dbDir . '/information_schema.json',
            ),
            true,
        );
        \assert(\is_array($data) && \is_array($data['tables']));
        \assert(\is_array($data['tables'][$table]));

        return $data['tables'][$table];
    }

    /**
     * @return list<array<mixed>>
     */
    private function schemaRelations(): array
    {
        $data = json_decode(
            (string)file_get_contents(
                $this->dbDir . '/information_schema.json',
            ),
            true,
        );
        \assert(\is_array($data) && \is_array($data['relations']));

        $relations = [];

        foreach ($data['relations'] as $entry) {
            \assert(\is_array($entry));
            $relations[] = $entry;
        }

        return $relations;
    }

    /**
     * @param array<string,string> $relation
     */
    private function injectRelation(array $relation): void
    {
        $path = $this->dbDir . '/information_schema.json';
        $data = json_decode((string)file_get_contents($path), true);
        \assert(\is_array($data));
        $relations = $data['relations'] ?? [];
        \assert(\is_array($relations));
        $relations[] = $relation;
        $data['relations'] = $relations;
        unlink($path);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
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
}
