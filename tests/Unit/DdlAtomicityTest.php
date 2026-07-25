<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IssueCategory;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for createTable/dropTable atomicity: schema -> meta -> files order,
 * self-healing of the crash windows (registered table without meta/files)
 * on the first write and via repair, fresh file provisioning over garbage,
 * and the repair policy for orphan directories.
 */
final class DdlAtomicityTest
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
    public function createTableProvisionsSchemaMetaAndFiles(): void
    {
        $this->db->createTable($this->userSchema());

        Assert::true($this->db->hasTable('users'));
        Assert::true(file_exists($this->dbDir . '/users/users.ndjson'));

        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        \assert(\is_array($meta) && \is_array($meta['users']));
        Assert::same($meta['users'], [
            'lastInsertedId' => 0,
            'lineCount'      => 0,
            'byteSize'       => 0,
            'indexFormat'    => 2,
        ]);

        $id = $this->db->insert('users', ['name' => 'first']);
        Assert::same($id, 1);
    }

    #[Test]
    public function duplicateCreateTableThrows(): void
    {
        $this->db->createTable($this->userSchema());

        $caught = null;

        try {
            $this->db->createTable($this->userSchema());
        } catch (JsonProviderException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getErrorKey(), 'TableAlreadyExists');
    }

    #[Test]
    public function createTableOverGarbageFileSucceedsWithEmptyTable(): void
    {
        mkdir($this->dbDir . '/users', 0755, true);
        file_put_contents(
            $this->dbDir . '/users/users.ndjson',
            '{"id":77,"name":"ghost"}' . "\n",
        );

        $this->db->createTable($this->userSchema());

        Assert::same(
            file_get_contents($this->dbDir . '/users/users.ndjson'),
            '',
        );
        Assert::same($this->db->table('users')->count(), 0);

        $id = $this->db->insert('users', ['name' => 'fresh']);
        Assert::same($id, 1);
    }

    #[Test]
    public function schemaOnlyCrashWindowHealsOnFirstInsert(): void
    {
        $registry = new SchemaRegistry(new JsonStorage($this->dbDir));
        $registry->registerTable($this->userSchema());

        Assert::true($this->db->hasTable('users'));

        $id = $this->db->insert('users', ['name' => 'healed']);
        Assert::same($id, 1);

        $rows = $this->db->table('users')->selectAllByArray();
        Assert::count($rows, 1);
        Assert::same($rows[0]['name'], 'healed');
    }

    #[Test]
    public function schemaAndMetaCrashWindowHealsOnFirstInsert(): void
    {
        $registry = new SchemaRegistry(new JsonStorage($this->dbDir));
        $registry->registerTable($this->userSchema());

        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        \assert(\is_array($meta));
        $meta['users'] = [
            'lastInsertedId' => 0,
            'lineCount'      => 0,
            'byteSize'       => 0,
        ];
        file_put_contents(
            $this->dbDir . '/meta.json',
            json_encode($meta, JSON_PRETTY_PRINT),
        );

        $id = $this->db->insert('users', ['name' => 'healed']);
        Assert::same($id, 1);
        Assert::true(file_exists($this->dbDir . '/users/users.ndjson'));
    }

    #[Test]
    public function schemaOnlyCrashWindowIsRepairedExplicitly(): void
    {
        $registry = new SchemaRegistry(new JsonStorage($this->dbDir));
        $registry->registerTable($this->userSchema());

        $report = $this->db->repairTable('users');

        $repaired = false;

        foreach ($report->issues as $issue) {
            if (
                $issue->category === IssueCategory::TABLE_FILE_MISSING
                && $issue->repaired
            ) {
                $repaired = true;
            }
        }

        Assert::true($repaired);
        Assert::true(file_exists($this->dbDir . '/users/users.ndjson'));

        $id = $this->db->insert('users', ['name' => 'post-repair']);
        Assert::same($id, 1);
    }

    #[Test]
    public function metaMissingWithExistingRowsResumesIdSequence(): void
    {
        $this->db->createTable($this->userSchema());
        $this->db->insert('users', ['name' => 'a']);
        $this->db->insert('users', ['name' => 'b']);

        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        \assert(\is_array($meta));
        unset($meta['users']);
        file_put_contents(
            $this->dbDir . '/meta.json',
            json_encode($meta, JSON_PRETTY_PRINT),
        );

        $id = $this->db->insert('users', ['name' => 'c']);
        Assert::same($id, 3);

        $ids = array_column(
            $this->db->table('users')->selectAllByArray(),
            'id',
        );
        sort($ids);
        Assert::same($ids, [1, 2, 3]);
    }

    #[Test]
    public function dropTableRemovesEverythingAndIsIdempotent(): void
    {
        $this->db->createTable($this->userSchema());
        $this->db->insert('users', ['name' => 'x']);

        $this->db->dropTable('users');

        Assert::false($this->db->hasTable('users'));
        Assert::false(is_dir($this->dbDir . '/users'));
        Assert::false(
            file_exists($this->dbDir . '/.locks/table.users.lock'),
        );

        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        \assert(\is_array($meta));
        Assert::false(\array_key_exists('users', $meta));

        $this->db->dropTable('users');
        Assert::false($this->db->hasTable('users'));
    }

    #[Test]
    public function dropThenCreateSameNameYieldsCleanTable(): void
    {
        $this->db->createTable($this->userSchema());
        $this->db->insert('users', ['name' => 'old']);
        $this->db->dropTable('users');

        $this->db->createTable($this->userSchema());

        Assert::same($this->db->table('users')->count(), 0);

        $id = $this->db->insert('users', ['name' => 'new']);
        Assert::same($id, 1);
    }

    #[Test]
    public function createTableAfterCrashedDropDiscardsOrphanMeta(): void
    {
        $this->db->createTable($this->userSchema());
        $this->db->insert('users', ['name' => 'old-life-1']);
        $this->db->insert('users', ['name' => 'old-life-2']);

        $raw = file_get_contents($this->dbDir . '/information_schema.json');
        \assert($raw !== false);
        $schema = json_decode($raw, true);
        \assert(\is_array($schema) && \is_array($schema['tables']));
        unset($schema['tables']['users']);

        if ($schema['tables'] === []) {
            $schema['tables'] = new \stdClass();
        }

        file_put_contents(
            $this->dbDir . '/information_schema.json',
            json_encode($schema, JSON_PRETTY_PRINT),
        );

        $this->db->createTable($this->userSchema());

        Assert::same($this->db->table('users')->count(), 0);

        $id = $this->db->insert('users', ['name' => 'new-life']);
        Assert::same($id, 1);

        $rows = $this->db->table('users')->selectAllByArray();
        Assert::count($rows, 1);
        Assert::same($rows[0]['name'], 'new-life');
    }

    #[Test]
    public function healCrashWindowWithRestoredWatermarkReheals(): void
    {
        $this->db->createTable($this->userSchema());
        $this->db->insert('users', ['name' => 'a']);
        $this->db->insert('users', ['name' => 'b']);

        $metaPath = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($metaPath), true);
        \assert(\is_array($meta));
        $meta['users'] = [
            'lastInsertedId' => 2,
            'lineCount'      => 0,
            'byteSize'       => 0,
        ];
        file_put_contents(
            $metaPath,
            json_encode($meta, JSON_PRETTY_PRINT),
        );

        $id = $this->db->insert('users', ['name' => 'c']);
        Assert::same($id, 3);

        $ids = array_column(
            $this->db->table('users')->selectAllByArray(),
            'id',
        );
        sort($ids);
        Assert::same($ids, [1, 2, 3]);
    }

    #[Test]
    public function trailingSlashResolvesToSameInstance(): void
    {
        $a = JsonDataProvider::getInstance($this->dbDir);
        $b = JsonDataProvider::getInstance($this->dbDir . '/');
        $c = JsonDataProvider::getInstance($this->dbDir . '//');

        Assert::same($b, $a);
        Assert::same($c, $a);
        Assert::true(JsonDataProvider::exists($this->dbDir . '/'));
    }

    #[Test]
    public function orphanDirWithDataSurvivesRepair(): void
    {
        mkdir($this->dbDir . '/stray', 0755, true);
        file_put_contents(
            $this->dbDir . '/stray/stray.ndjson',
            '{"id":1,"name":"precious"}' . "\n",
        );

        $report = $this->db->repair();

        $flagged = false;

        foreach ($report->issues as $issue) {
            if (
                $issue->category === IssueCategory::ORPHAN_DB_ENTRY
                && $issue->repairError !== null
            ) {
                $flagged = true;
                Assert::string($issue->repairError)
                    ->contains('manual removal');
            }
        }

        Assert::true($flagged);
        Assert::true(
            file_exists($this->dbDir . '/stray/stray.ndjson'),
        );
    }

    #[Test]
    public function emptyOrphanDirIsRemovedByRepair(): void
    {
        mkdir($this->dbDir . '/husk', 0755, true);
        touch($this->dbDir . '/husk/husk.ndjson');

        $report = $this->db->repair();

        $repaired = false;

        foreach ($report->issues as $issue) {
            if (
                $issue->category === IssueCategory::ORPHAN_DB_ENTRY
                && $issue->repaired
            ) {
                $repaired = true;
            }
        }

        Assert::true($repaired);
        Assert::false(is_dir($this->dbDir . '/husk'));
    }

    private function userSchema(): TableSchema
    {
        return TableSchema::create(
            name: 'users',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'name' => 'string'],
            indexes: [],
        );
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
        return TempDir::root('jp-ddl-tests');
    }
}
