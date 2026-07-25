<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IssueCategory;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for renameTable: schema key, relations, meta entry (counters
 * preserved) and the physical directory + data file all move to the new
 * name; a crash at any point leaves the _pendingRename marker that
 * repair() reconciles deterministically by the actual schema state.
 */
final class RenameTableTest
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
            name: 'src',
            columns: ['id' => 'int', 'grp' => 'int', 'name' => 'string'],
            indexes: [new IndexSchema(
                'idx_grp',
                [new IndexFieldSchema('grp', SortDirectionEnum::ASC)],
            )],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'child',
            columns: ['id' => 'int', 'src_id' => 'int'],
        ));
        $this->injectRelation([
            'from'       => 'child',
            'foreignKey' => 'src_id',
            'to'         => 'src',
            'references' => 'id',
            'type'       => 'belongsTo',
            'onDelete'   => 'cascade',
        ]);

        foreach ([[1, 'a'], [2, 'b'], [1, 'c']] as [$grp, $name]) {
            $this->db->insert('src', ['grp' => $grp, 'name' => $name]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function happyPathMovesEverything(): void
    {
        $this->db->renameTable('src', 'dst');

        Assert::false($this->db->hasTable('src'));
        Assert::true($this->db->hasTable('dst'));

        Assert::true(is_dir($this->dbDir . '/dst'));
        Assert::false(is_dir($this->dbDir . '/src'));
        Assert::true(file_exists($this->dbDir . '/dst/dst.ndjson'));
        Assert::true(
            file_exists($this->dbDir . '/dst/idx_grp.index.ndjson'),
        );

        $meta = $this->meta();
        Assert::false(isset($meta['src']));
        Assert::false(isset($meta['_pendingRename']));

        $entry = $this->metaEntry('dst');
        Assert::same($entry['lastInsertedId'], 3);
        Assert::same($entry['lineCount'], 3);

        $relation = $this->schemaRelations()[0];
        Assert::same($relation['to'], 'dst');
        Assert::same($relation['from'], 'child');

        $viaIndex = $this->db->table('dst')
            ->where('grp', '=', 1)->selectAllByArray();
        Assert::count($viaIndex, 2);

        Assert::same($this->db->insert('dst', [
            'grp'  => 5,
            'name' => 'd',
        ]), 4);
    }

    #[Test]
    public function guardsRejectBadArguments(): void
    {
        try {
            $this->db->renameTable('missing', 'dst');
            Assert::fail('an unknown source table must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'TableNotFound');
        }

        try {
            $this->db->renameTable('src', 'child');
            Assert::fail('a taken target name must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'TableAlreadyExists');
        }

        try {
            $this->db->renameTable('src', 'пункт');
            Assert::fail('an invalid target name must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'InvalidTableName');
        }

        Assert::true($this->db->hasTable('src'));
    }

    #[Test]
    public function crashAfterMarkerBeforeSchemaRollsBack(): void
    {
        $this->setMarker('src', 'dst');

        $report = $this->db->validate();
        Assert::count(
            $report->issuesByCategory(IssueCategory::RENAME_INCOMPLETE),
            2,
        );

        $repairReport = $this->db->repair();
        $reconciled = $repairReport->issuesByCategory(
            IssueCategory::RENAME_INCOMPLETE,
        );
        Assert::count($reconciled, 1);
        Assert::true($reconciled[0]->repaired);

        Assert::true($this->db->hasTable('src'));
        Assert::false($this->db->hasTable('dst'));
        Assert::false(isset($this->meta()['_pendingRename']));
        Assert::count($this->db->table('src')->selectAllByArray(), 3);
    }

    #[Test]
    public function crashAfterSchemaBeforePhysicsRollsForward(): void
    {
        $this->setMarker('src', 'dst');
        $this->renameSchemaKey('src', 'dst');

        $repairReport = $this->db->repair();
        $reconciled = $repairReport->issuesByCategory(
            IssueCategory::RENAME_INCOMPLETE,
        );
        Assert::count($reconciled, 1);
        Assert::true($reconciled[0]->repaired);

        Assert::true($this->db->hasTable('dst'));
        Assert::true(is_dir($this->dbDir . '/dst'));
        Assert::false(is_dir($this->dbDir . '/src'));
        Assert::true(file_exists($this->dbDir . '/dst/dst.ndjson'));

        $meta = $this->meta();
        Assert::false(isset($meta['src']));
        Assert::false(isset($meta['_pendingRename']));
        Assert::same($this->metaEntry('dst')['lastInsertedId'], 3);

        Assert::count($this->db->table('dst')->selectAllByArray(), 3);
    }

    #[Test]
    public function crashAfterDirRenameBeforeFileRenameRollsForward(): void
    {
        $this->setMarker('src', 'dst');
        $this->renameSchemaKey('src', 'dst');
        rename($this->dbDir . '/src', $this->dbDir . '/dst');

        $this->db->repair();

        Assert::true(file_exists($this->dbDir . '/dst/dst.ndjson'));
        Assert::false(file_exists($this->dbDir . '/dst/src.ndjson'));
        Assert::count($this->db->table('dst')->selectAllByArray(), 3);
        Assert::false(isset($this->meta()['_pendingRename']));
    }

    #[Test]
    public function writesIntoTheCrashWindowAreRefused(): void
    {
        $this->setMarker('src', 'dst');
        $this->renameSchemaKey('src', 'dst');

        try {
            $this->db->insert('dst', ['grp' => 9, 'name' => 'x']);
            Assert::fail('a write into the rename crash window must refuse');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'RenameIncomplete');
        }

        Assert::true(file_exists($this->dbDir . '/src/src.ndjson'));
        Assert::false(is_dir($this->dbDir . '/dst'));

        $this->db->repair();
        Assert::count($this->db->table('dst')->selectAllByArray(), 3);
    }

    #[Test]
    public function singleTableRepairRefusesToHealTheWindow(): void
    {
        $this->setMarker('src', 'dst');
        $this->renameSchemaKey('src', 'dst');

        $dataBefore = file_get_contents($this->dbDir . '/src/src.ndjson');

        $report = $this->db->repairTable('dst');

        $renameIssues = $report->issuesByCategory(
            IssueCategory::RENAME_INCOMPLETE,
        );
        Assert::count($renameIssues, 1);
        Assert::false($renameIssues[0]->repaired);
        Assert::notNull($renameIssues[0]->repairError);

        $fileMissing = $report->issuesByCategory(
            IssueCategory::TABLE_FILE_MISSING,
        );

        foreach ($fileMissing as $issue) {
            Assert::false($issue->repaired);
        }

        Assert::same(
            file_get_contents($this->dbDir . '/src/src.ndjson'),
            $dataBefore,
        );

        $this->db->repair();
        Assert::count($this->db->table('dst')->selectAllByArray(), 3);
        Assert::false(isset($this->meta()['_pendingRename']));
    }

    #[Test]
    public function secondRenameIsRefusedWhileMarkerAlive(): void
    {
        $this->setMarker('src', 'dst');
        $this->renameSchemaKey('src', 'dst');

        try {
            $this->db->renameTable('dst', 'third');
            Assert::fail('a rename over a live marker must refuse');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'RenameIncomplete');
        }

        $meta = $this->meta();
        \assert(\is_array($meta['_pendingRename']));
        Assert::same($meta['_pendingRename']['from'], 'src');
        Assert::same($meta['_pendingRename']['to'], 'dst');
    }

    #[Test]
    public function pendingRenameMarkerDoesNotLeakAsTable(): void
    {
        $this->setMarker('src', 'dst');

        $report = $this->db->validate();
        $orphans = $report->issuesByCategory(
            IssueCategory::META_ORPHAN_ENTRY,
        );

        foreach ($orphans as $issue) {
            Assert::true($issue->tableName !== '_pendingRename');
        }

        $this->db->repair();
        Assert::true($this->db->hasTable('src'));
    }

    private function setMarker(string $from, string $to): void
    {
        $path = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($path), true);
        \assert(\is_array($meta));
        $meta['_pendingRename'] = ['from' => $from, 'to' => $to];
        unlink($path);
        file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT));
    }

    private function renameSchemaKey(string $from, string $to): void
    {
        $path = $this->dbDir . '/information_schema.json';
        $data = json_decode((string)file_get_contents($path), true);
        \assert(\is_array($data) && \is_array($data['tables']));
        \assert(\is_array($data['relations']));

        $data['tables'][$to] = $data['tables'][$from];
        unset($data['tables'][$from]);

        $relations = [];

        foreach ($data['relations'] as $entry) {
            \assert(\is_array($entry));

            if (($entry['from'] ?? null) === $from) {
                $entry['from'] = $to;
            }

            if (($entry['to'] ?? null) === $from) {
                $entry['to'] = $to;
            }

            $relations[] = $entry;
        }

        $data['relations'] = $relations;
        unlink($path);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

        /*
         * 'src' and 'dst' have equal lengths, so the rewritten file can
         * share size, mtime second and (on tmpfs) even the inode with
         * the original — bump mtime so the schema stat revalidation
         * re-reads it.
         */
        touch($path, time() + 2);
    }

    /**
     * @return array<mixed>
     */
    private function meta(): array
    {
        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        \assert(\is_array($meta));

        return $meta;
    }

    /**
     * @return array<mixed>
     */
    private function metaEntry(string $table): array
    {
        $meta = $this->meta();
        \assert(\is_array($meta[$table]));

        return $meta[$table];
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

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-renametable-tests');
    }
}
