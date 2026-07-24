<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Backup\BackupManifest;
use AV\JsonProvider\Services\Integrity\IssueCategory;
use AV\JsonProvider\Tests\Support\Fixture;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for JsonDataProvider::backup() and restore().
 *
 * Roundtrip: backup → mutation → restore brings the DB back to the snapshot
 * state. Restore failure modes are also exercised: destination inside DB,
 * existing target, corrupt archive, schema mismatch (with rollback).
 *
 * Manifest v2: id counters and sha256 member checksums travel in the
 * manifest; a tampered member fails the restore before anything is
 * applied; a legacy archive (no counters/checksums) restores without
 * verification. Adopting restores replace the schema, materialize
 * archive-only tables and prune extra ones only under the explicit flag.
 */
final class BackupRestoreTest
{
    private const string TMP_DIR = '/tmp/jp-backup-tests';
    private const string ADOPT_DIR = '/tmp/jp-adopt-tests';

    #[BeforeTest]
    public function setUp(): void
    {
        if (!is_dir(self::TMP_DIR)) {
            mkdir(self::TMP_DIR, 0755, true);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (is_dir(self::TMP_DIR)) {
            $entries = glob(self::TMP_DIR . '/*');

            if ($entries !== false) {
                foreach ($entries as $f) {
                    if (is_file($f)) {
                        unlink($f);
                    }
                }
            }
        }

        $this->removeDir(self::ADOPT_DIR);
    }

    #[Test]
    public function backupCreatesArchiveAtExplicitPath(): void
    {
        $path = self::TMP_DIR . '/explicit.tar.gz';
        $written = Fixture::db()->backup($path);

        Assert::same($written, $path);
        Assert::true(file_exists($path));
        Assert::int(filesize($path))->greaterThan(0);
    }

    #[Test]
    public function backupGeneratesNameWhenDestinationIsDirectory(): void
    {
        $written = Fixture::db()->backup(self::TMP_DIR);

        Assert::true(str_starts_with($written, self::TMP_DIR . '/backup-'));
        Assert::true(str_ends_with($written, '.tar.gz'));
        Assert::true(file_exists($written));
    }

    #[Test]
    public function backupRefusesDestinationInsideDb(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('outside the DB directory');

        Fixture::db()->backup(Fixture::DB_PATH . '/internal.tar.gz');
    }

    #[Test]
    public function backupRefusesExistingFile(): void
    {
        $path = self::TMP_DIR . '/existing.tar.gz';
        file_put_contents($path, 'not really an archive');

        Expect::exception(StorageException::class)
            ->withMessageContaining('already exists');

        Fixture::db()->backup($path);
    }

    #[Test]
    public function roundtripRestoresTableContents(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $beforeCount = $tbl->count();
        $beforeRows = $tbl->selectAllByArray();

        $archive = $db->backup(self::TMP_DIR . '/roundtrip.tar.gz');

        $newId = $tbl->insertByArray(['name' => 'TempBackup', 'sort' => 4242]);
        Assert::same($tbl->count(), $beforeCount + 1);

        $db->restore($archive);

        Assert::same($tbl->count(), $beforeCount);

        $afterRows = $tbl->selectAllByArray();
        Assert::same($afterRows, $beforeRows);

        $row = $tbl->where('id', '=', $newId)->selectOneByArray();
        Assert::null($row);
    }

    #[Test]
    public function restoreRebuildsIndexesAndMeta(): void
    {
        $db = Fixture::db();

        $archive = $db->backup(self::TMP_DIR . '/rebuild.tar.gz');

        file_put_contents(
            Fixture::DB_PATH . '/products/idx_category.index.ndjson',
            "GARBAGE\n",
        );

        $db->restore($archive);

        $report = $db->validate();
        Assert::false($report->hasErrors(), $report->format());
    }

    #[Test]
    public function restoreRollsBackOnSchemaMismatch(): void
    {
        $db = Fixture::db();
        $archive = $db->backup(self::TMP_DIR . '/mismatch.tar.gz');

        $tamperedPath = self::TMP_DIR . '/tampered.tar.gz';
        $this->buildTamperedArchive($archive, $tamperedPath);

        $beforeCount = $db->table('products')->count();

        try {
            $db->restore($tamperedPath);
            Assert::fail('restore should have thrown on schema mismatch');
        } catch (StorageException $e) {
            Assert::string(strtolower($e->getMessage()))->contains('schema');
        }

        Assert::same($db->table('products')->count(), $beforeCount);
    }

    #[Test]
    public function restoreThrowsOnMissingArchive(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('archive not found');

        Fixture::db()->restore(self::TMP_DIR . '/does-not-exist.tar.gz');
    }

    #[Test]
    public function restoreThrowsOnCorruptArchive(): void
    {
        $path = self::TMP_DIR . '/garbage.tar.gz';
        file_put_contents($path, 'this is definitely not a tar.gz');

        Expect::exception(StorageException::class)
            ->withMessageContaining('archive');

        Fixture::db()->restore($path);
    }

    #[Test]
    public function manifestCarriesCountersAndChecksums(): void
    {
        $db = Fixture::db();
        $archive = $db->backup(self::TMP_DIR . '/manifest-v2.tar.gz');

        $manifest = $this->readManifestFromArchive($archive);

        Assert::false($manifest->legacy);

        foreach (['categories', 'products', 'tags'] as $table) {
            $maxId = 0;

            foreach ($db->table($table)->selectAllByArray() as $row) {
                $id = $row['id'];
                \assert(\is_int($id));
                $maxId = max($maxId, $id);
            }

            Assert::true(
                ($manifest->counters[$table] ?? 0) >= $maxId,
                'counter of ' . $table . ' must cover the stored ids',
            );

            $member = 'tables/' . $table . '.ndjson';
            $bytes = file_get_contents('phar://' . $archive . '/' . $member);
            \assert(\is_string($bytes));
            Assert::same(
                $manifest->checksums[$member] ?? '',
                hash('sha256', $bytes),
            );
        }

        $schemaBytes = file_get_contents(
            'phar://' . $archive . '/information_schema.json',
        );
        \assert(\is_string($schemaBytes));
        Assert::same(
            $manifest->checksums['information_schema.json'] ?? '',
            hash('sha256', $schemaBytes),
        );
    }

    #[Test]
    public function tamperedTableMemberFailsChecksumAndLeavesDbIntact(): void
    {
        $db = Fixture::db();
        $archive = $db->backup(self::TMP_DIR . '/tamper-member.tar.gz');

        $tampered = self::TMP_DIR . '/tamper-member-x.tar.gz';
        copy($archive, $tampered);
        $phar = new \PharData($tampered);
        $phar->addFromString(
            'tables/products.ndjson',
            '{"id":1,"name":"evil","category_id":1,'
                . '"price":1.0,"in_stock":true}' . "\n",
        );
        unset($phar);

        $before = $db->table('products')->count();

        try {
            $db->restore($tampered);
            Assert::fail('checksum mismatch must abort the restore');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'BACKUP_CHECKSUM_MISMATCH');
            Assert::string($e->getMessage())
                ->contains('tables/products.ndjson');
        }

        Assert::same($db->table('products')->count(), $before);
    }

    #[Test]
    public function legacyArchiveWithoutChecksumsRestores(): void
    {
        $db = Fixture::db();
        $archive = $db->backup(self::TMP_DIR . '/legacy.tar.gz');

        $legacy = self::TMP_DIR . '/legacy-x.tar.gz';
        copy($archive, $legacy);
        $this->stripManifestV2Fields($legacy);

        $manifest = $this->readManifestFromArchive($legacy);
        Assert::true($manifest->legacy);
        Assert::same($manifest->counters, []);
        Assert::same($manifest->checksums, []);

        $before = $db->table('products')->selectAllByArray();

        $db->restore($legacy);

        Assert::same($db->table('products')->selectAllByArray(), $before);
    }

    #[Test]
    public function restoreDoesNotReuseDeletedHighWaterId(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $tempId = $tbl->insertByArray(['name' => 'HighWater', 'sort' => 1]);
        $tbl->deleteById($tempId);

        $archive = $db->backup(self::TMP_DIR . '/highwater.tar.gz');
        $db->restore($archive);

        $nextId = $tbl->insertByArray(['name' => 'AfterRestore', 'sort' => 2]);

        Assert::same(
            $nextId,
            $tempId + 1,
            'the deleted high-water id must never be re-minted: the '
                . 'manifest counter, not max(id), wins',
        );

        $tbl->deleteById($nextId);
    }

    #[Test]
    public function rollbackRestoresDataAndCountersFromSnapshot(): void
    {
        $db = Fixture::db();
        $archive = $db->backup(self::TMP_DIR . '/rollback-src.tar.gz');

        $broken = self::TMP_DIR . '/rollback-broken.tar.gz';
        copy($archive, $broken);
        $this->stripManifestV2Fields($broken);
        $phar = new \PharData($broken);
        unset($phar['tables/tags.ndjson'], $phar);

        $beforeProducts = $db->table('products')->selectAllByArray();
        $beforeTags = $db->table('tags')->selectAllByArray();

        try {
            $db->restore($broken);
            Assert::fail('a missing member must fail the restore');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'RESTORE_FAILED');
            Assert::string($e->getMessage())->contains('rolled back');
        }

        Assert::same(
            $db->table('products')->selectAllByArray(),
            $beforeProducts,
        );
        Assert::same($db->table('tags')->selectAllByArray(), $beforeTags);

        $probe = $db->table('categories')
            ->insertByArray(['name' => 'PostRollback', 'sort' => 3]);
        Assert::count(
            $db->table('categories')
                ->where('id', '=', $probe)->selectAllByArray(),
            1,
        );
        $db->table('categories')->deleteById($probe);
    }

    #[Test]
    public function restoreSweepsUndeclaredFilesFromTableDirs(): void
    {
        $db = Fixture::db();
        $archive = $db->backup(self::TMP_DIR . '/sweep.tar.gz');

        file_put_contents(
            Fixture::DB_PATH . '/products/orphan_stray.txt',
            'junk',
        );
        file_put_contents(
            Fixture::DB_PATH . '/products/leftover.ndjson.tmp',
            'half-written',
        );

        $db->restore($archive);

        Assert::false(
            file_exists(Fixture::DB_PATH . '/products/orphan_stray.txt'),
        );
        Assert::false(
            file_exists(Fixture::DB_PATH . '/products/leftover.ndjson.tmp'),
        );

        $report = $db->validate();
        Assert::count(
            $report->issuesByCategory(IssueCategory::ORPHAN_INDEX_FILE),
            0,
            $report->format(),
        );
    }

    #[Test]
    public function exportNeverIncludesUndeclaredFiles(): void
    {
        $db = Fixture::db();

        file_put_contents(
            Fixture::DB_PATH . '/products/concurrent.ndjson.tmp',
            'in-flight',
        );

        $archive = $db->backup(self::TMP_DIR . '/no-tmp.tar.gz');
        unlink(Fixture::DB_PATH . '/products/concurrent.ndjson.tmp');

        $members = $this->listArchiveMembers($archive);
        sort($members);

        $declared = ['information_schema.json', 'manifest.json'];

        foreach ($this->readManifestFromArchive($archive)->tables as $t) {
            $declared[] = 'tables/' . $t . '.ndjson';
        }

        sort($declared);

        Assert::same(
            $members,
            $declared,
            'the archive must hold exactly the schema-declared members — '
                . 'never a *.tmp or stray file',
        );
    }

    #[Test]
    public function adoptRestoreMaterializesArchiveIntoEmptyDatabase(): void
    {
        $source = Fixture::db();
        $archive = $source->backup(self::TMP_DIR . '/adopt-src.tar.gz');

        $target = JsonDataProvider::createDatabase(
            self::ADOPT_DIR . '/' . uniqid('db', true),
        );

        $target->restore($archive, adoptArchivedSchema: true);

        Assert::same(
            $target->table('products')->count(),
            $source->table('products')->count(),
        );
        Assert::same(
            $target->table('categories')->selectAllByArray(),
            $source->table('categories')->selectAllByArray(),
        );

        $report = $target->validate();
        Assert::false($report->hasErrors(), $report->format());

        $maxId = 0;

        foreach ($target->table('tags')->selectAllByArray() as $row) {
            $id = $row['id'];
            \assert(\is_int($id));
            $maxId = max($maxId, $id);
        }

        $newId = $target->table('tags')
            ->insertByArray(['product_id' => 1, 'label' => 'fresh']);
        Assert::same($newId, $maxId + 1);
    }

    #[Test]
    public function adoptRestoreRefusesExtraTablesWithoutPrune(): void
    {
        $source = Fixture::db();
        $archive = $source->backup(self::TMP_DIR . '/adopt-extra.tar.gz');

        $target = JsonDataProvider::createDatabase(
            self::ADOPT_DIR . '/' . uniqid('db', true),
        );
        $target->createTable(TableSchema::create(
            name: 'local_only',
            columns: ['id' => 'int', 'v' => 'string'],
        ));
        $target->insert('local_only', ['v' => 'precious']);

        try {
            $target->restore($archive, adoptArchivedSchema: true);
            Assert::fail('extra table must block the adopt without prune');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'BACKUP_SCHEMA_MISMATCH');
            Assert::string($e->getMessage())->contains('pruneExtraTables');
        }

        Assert::count($target->table('local_only')->selectAllByArray(), 1);

        $target->restore(
            $archive,
            adoptArchivedSchema: true,
            pruneExtraTables: true,
        );

        Assert::false($target->hasTable('local_only'));
        Assert::same(
            $target->table('products')->count(),
            $source->table('products')->count(),
        );

        $report = $target->validate();
        Assert::false($report->hasErrors(), $report->format());
    }

    #[Test]
    public function adoptRejectsInvalidArchivedSchema(): void
    {
        $source = Fixture::db();
        $archive = $source->backup(self::TMP_DIR . '/adopt-bad.tar.gz');

        $bad = self::TMP_DIR . '/adopt-bad-x.tar.gz';
        copy($archive, $bad);
        $this->stripManifestV2Fields($bad);
        $this->rewriteArchivedSchema(
            $bad,
            static function (array $schema): array {
                \assert(\is_array($schema['tables']));
                \assert(\is_array($schema['tables']['products']));
                \assert(
                    \is_array($schema['tables']['products']['columns']),
                );
                $schema['tables']['products']['columns']['price'] = 'varchar';

                return $schema;
            },
        );

        $target = JsonDataProvider::createDatabase(
            self::ADOPT_DIR . '/' . uniqid('db', true),
        );

        Expect::exception(StorageException::class);

        $target->restore($bad, adoptArchivedSchema: true);
    }

    #[Test]
    public function adoptRejectsTraversalTableName(): void
    {
        $source = Fixture::db();
        $archive = $source->backup(self::TMP_DIR . '/adopt-trav.tar.gz');

        $bad = self::TMP_DIR . '/adopt-trav-x.tar.gz';
        copy($archive, $bad);
        $this->stripManifestV2Fields($bad);
        $this->rewriteArchivedSchema(
            $bad,
            static function (array $schema): array {
                \assert(\is_array($schema['tables']));
                $schema['tables']['..'] = [
                    'columns' => ['id' => 'int'],
                ];

                return $schema;
            },
        );

        $target = JsonDataProvider::createDatabase(
            self::ADOPT_DIR . '/' . uniqid('db', true),
        );

        Expect::exception(StorageException::class);

        $target->restore($bad, adoptArchivedSchema: true);
    }

    #[Test]
    public function tamperedSchemaMemberFailsChecksumBeforeAdopt(): void
    {
        $source = Fixture::db();
        $archive = $source->backup(self::TMP_DIR . '/adopt-sum.tar.gz');

        $bad = self::TMP_DIR . '/adopt-sum-x.tar.gz';
        copy($archive, $bad);
        $this->rewriteArchivedSchema(
            $bad,
            static function (array $schema): array {
                \assert(\is_array($schema['tables']));
                $schema['tables']['injected'] = [
                    'columns' => ['id' => 'int'],
                ];

                return $schema;
            },
        );

        $target = JsonDataProvider::createDatabase(
            self::ADOPT_DIR . '/' . uniqid('db', true),
        );

        try {
            $target->restore($bad, adoptArchivedSchema: true);
            Assert::fail('schema tampering must fail the checksum');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'BACKUP_CHECKSUM_MISMATCH');
            Assert::string($e->getMessage())
                ->contains('information_schema.json');
        }

        Assert::false($target->hasTable('injected'));
    }

    private function readManifestFromArchive(string $archive): BackupManifest
    {
        $raw = file_get_contents('phar://' . $archive . '/manifest.json');
        \assert(\is_string($raw));
        $decoded = json_decode($raw, true);
        \assert(\is_array($decoded));

        return BackupManifest::fromArray($decoded);
    }

    /**
     * Downgrades an archive's manifest to the legacy shape: the counters
     * and checksums keys are removed entirely.
     */
    private function stripManifestV2Fields(string $archive): void
    {
        $raw = file_get_contents('phar://' . $archive . '/manifest.json');
        $manifest = json_decode((string)$raw, true);
        \assert(\is_array($manifest));
        unset($manifest['counters'], $manifest['checksums']);

        $phar = new \PharData($archive);
        $phar->addFromString(
            'manifest.json',
            (string)json_encode($manifest, JSON_PRETTY_PRINT),
        );
        unset($phar);
    }

    /**
     * @param callable(array<mixed>): array<mixed> $mutate
     */
    private function rewriteArchivedSchema(
        string $archive,
        callable $mutate,
    ): void {
        $raw = file_get_contents(
            'phar://' . $archive . '/information_schema.json',
        );
        $schema = json_decode((string)$raw, true);
        \assert(\is_array($schema));

        $phar = new \PharData($archive);
        $phar->addFromString(
            'information_schema.json',
            (string)json_encode($mutate($schema), JSON_PRETTY_PRINT),
        );
        unset($phar);
    }

    /**
     * @return list<string>
     */
    private function listArchiveMembers(string $archive): array
    {
        $members = [];
        $phar = new \PharData($archive);

        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            \assert($file instanceof \PharFileInfo);
            $members[] = substr(
                $file->getPathname(),
                \strlen('phar://' . $archive . '/'),
            );
        }

        return $members;
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

    /**
     * Copies $sourcePath to $targetPath, then tampers with the manifest so it
     * claims a table that does not exist in the current schema.
     */
    private function buildTamperedArchive(
        string $sourcePath,
        string $targetPath,
    ): void {
        copy($sourcePath, $targetPath);

        $phar = new \PharData($targetPath);

        $raw = file_get_contents('phar://' . $targetPath . '/manifest.json');
        $manifest = json_decode((string)$raw, true);
        \assert(\is_array($manifest));
        \assert(isset($manifest['tables']) && \is_array($manifest['tables']));
        $manifest['tables'][] = 'nonexistent_table';

        $encoded = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        );

        $phar->addFromString('manifest.json', (string)$encoded);
    }
}
