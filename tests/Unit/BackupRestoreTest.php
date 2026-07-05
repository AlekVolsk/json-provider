<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
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
 */
final class BackupRestoreTest
{
    private const string TMP_DIR = '/tmp/jp-backup-tests';

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

        // Make sure the new row inserted between backup and restore is gone.
        $row = $tbl->where('id', '=', $newId)->selectOneByArray();
        Assert::null($row);
    }

    #[Test]
    public function restoreRebuildsIndexesAndMeta(): void
    {
        $db = Fixture::db();

        $archive = $db->backup(self::TMP_DIR . '/rebuild.tar.gz');

        // Tamper an index file before restore — it must come back canonical.
        file_put_contents(
            Fixture::DB_PATH . '/products/idx_category.index.ndjson',
            "GARBAGE\n",
        );

        $db->restore($archive);

        // Validator must report a clean DB after restore.
        $report = $db->validate();
        Assert::false($report->hasErrors(), $report->format());
    }

    #[Test]
    public function restoreRollsBackOnSchemaMismatch(): void
    {
        $db = Fixture::db();
        $archive = $db->backup(self::TMP_DIR . '/mismatch.tar.gz');

        // Build a tampered archive: copy the original and then break the
        // manifest so it claims a table that is not in the current schema.
        $tamperedPath = self::TMP_DIR . '/tampered.tar.gz';
        $this->buildTamperedArchive($archive, $tamperedPath);

        $beforeCount = $db->table('products')->count();

        try {
            $db->restore($tamperedPath);
            Assert::fail('restore should have thrown on schema mismatch');
        } catch (StorageException $e) {
            Assert::string(strtolower($e->getMessage()))->contains('schema');
        }

        // DB must be untouched: the rollback was via safety snapshot, but we
        // never reached the apply step — schema mismatch is detected before
        // the snapshot is taken.
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

        // Inject a manifest that claims a non-existent table, so the
        // restore-side schema match check rejects the archive.
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
