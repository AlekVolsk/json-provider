<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Backup\PharArchive;
use AV\JsonProvider\Tests\Support\FsList;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Backup and restore under conditions outside the happy path:
 *  - a structurally broken database is not exported, while restoring a
 *    broken database from a healthy archive still works;
 *  - repeated backup/restore on the same path within one process sees the
 *    current file, not an archive ext-phar cached earlier;
 *  - a failed backup reports that nothing was written and leaves no
 *    temporary files behind;
 *  - restore leaves no temporary copies or snapshots in the temp dir.
 */
final class BackupHardeningTest
{
    private string $root = '';

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = self::tmpRoot() . '/' . uniqid('run', true);
        mkdir($this->root, 0755, true);
        $this->db = JsonDataProvider::createDatabase($this->root . '/db');

        foreach (['a', 'b'] as $table) {
            $this->db->createTable(TableSchema::create(
                name: $table,
                columns: ['v' => 'string'],
            ));
            $this->db->insert($table, ['v' => $table . '1']);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (is_dir($this->root . '/ro')) {
            chmod($this->root . '/ro', 0755);
        }

        TempDir::remove(self::tmpRoot());
    }

    #[Test]
    public function backupRefusesDatabaseWithMissingDataFile(): void
    {
        unlink($this->root . '/db/b/b.ndjson');

        $this->expectErrorKey(
            'BackupSourceInvalid',
            fn () => $this->db->backup($this->root . '/out.tar.gz'),
        );
        Assert::same(FsList::entries($this->root), ['db']);
    }

    #[Test]
    public function backupRefusesDatabaseWithUnfinishedRename(): void
    {
        $this->db->renameTable('b', 'c');
        rename($this->root . '/db/c/c.ndjson', $this->root . '/db/c/b.ndjson');
        rename($this->root . '/db/c', $this->root . '/db/b');
        $metaPath = $this->root . '/db/meta.json';
        $meta = json_decode((string)file_get_contents($metaPath), true);
        Assert::array($meta);
        $meta['_pendingRename'] = ['from' => 'b', 'to' => 'c'];
        file_put_contents($metaPath, json_encode($meta));

        $this->expectErrorKey(
            'BackupSourceInvalid',
            fn () => $this->db->backup($this->root . '/out.tar.gz'),
        );
        Assert::same(FsList::entries($this->root), ['db']);
    }

    #[Test]
    public function brokenDatabaseRestoresFromHealthyArchive(): void
    {
        $archive = $this->db->backup($this->root . '/good.tar.gz');
        unlink($this->root . '/db/b/b.ndjson');

        $this->db->restore($archive);

        Assert::same($this->db->table('b')->selectColumn('v'), ['b1']);
        Assert::false($this->db->validate()->hasErrors());
    }

    #[Test]
    public function restoreOfSamePathReadsReplacedArchive(): void
    {
        $path = $this->root . '/latest.tar.gz';
        $this->db->backup($path);
        $this->db->restore($path);

        $this->db->insert('a', ['v' => 'a2']);
        unlink($path);
        $this->db->backup($path);
        $this->db->insert('a', ['v' => 'a3']);

        $this->db->restore($path);

        Assert::same($this->db->table('a')->selectColumn('v'), ['a1', 'a2']);
    }

    #[Test]
    public function backupAfterRotationOfSamePathSucceeds(): void
    {
        $path = $this->root . '/latest.tar.gz';
        $this->db->backup($path);
        rename($path, $this->root . '/rotated-1.tar.gz');
        $this->db->insert('a', ['v' => 'a2']);

        Assert::same($this->db->backup($path), $path);
        Assert::same(
            FsList::entries($this->root),
            ['db', 'latest.tar.gz', 'rotated-1.tar.gz'],
        );
    }

    #[Test]
    public function unwritableDestinationIsReportedWithNothingWritten(): void
    {
        mkdir($this->root . '/ro', 0555);

        $this->expectErrorKey(
            'BackupDestinationNotWritable',
            fn () => $this->db->backup($this->root . '/missing/x.tar.gz'),
        );
        $this->expectErrorKey(
            'BackupDestinationNotWritable',
            fn () => $this->db->backup($this->root . '/ro/x.tar.gz'),
        );
        Assert::same(FsList::entries($this->root . '/ro'), []);
    }

    #[Test]
    public function failedArchiveWriteLeavesNoTemporaryFiles(): void
    {
        $dir = $this->root . '/site.phar.d';
        mkdir($dir);

        $this->expectErrorKey(
            'BackupWriteFailed',
            fn () => $this->db->backup($dir . '/b.tar.gz'),
        );
        Assert::same(FsList::entries($dir), []);

        $this->expectErrorKey(
            'BackupWriteFailed',
            fn () => $this->db->backup($dir . '/b.tar.gz'),
        );
        Assert::same(FsList::entries($dir), []);
    }

    #[Test]
    public function restoreLeavesNoTemporaryCopiesOrSnapshots(): void
    {
        $before = $this->tempArtifacts();
        $archive = $this->db->backup($this->root . '/b.tar.gz');

        $this->db->restore($archive);

        file_put_contents($this->root . '/broken.tar.gz', 'not an archive');

        try {
            $this->db->restore($this->root . '/broken.tar.gz');
            Assert::fail('a broken archive must be rejected');
        } catch (JsonProviderException) {
        }

        Assert::same($this->tempArtifacts(), $before);
    }

    #[Test]
    public function privateCopyAndSnapshotDirAreOwnerOnly(): void
    {
        $archive = $this->db->backup($this->root . '/b.tar.gz');

        $copy = PharArchive::privateCopy($archive);
        Assert::same(fileperms($copy) & 0o777, 0o600);
        PharArchive::discard($copy);
        Assert::false(file_exists($copy));

        $dir = PharArchive::privateDir('jp-test-');
        Assert::same(fileperms($dir) & 0o777, 0o700);
        rmdir($dir);
    }

    #[Test]
    public function defaultModeRestoresValuesVerbatimIntoCurrentSchema(): void
    {
        $source = JsonDataProvider::createDatabase($this->root . '/src');
        $source->createTable(TableSchema::create(
            name: 'n',
            columns: ['v' => 'string'],
        ));
        $source->insert('n', ['v' => 'one']);
        $archive = $source->backup($this->root . '/n.tar.gz');

        $target = JsonDataProvider::createDatabase($this->root . '/dst');
        $target->createTable(TableSchema::create(
            name: 'n',
            columns: ['v' => 'int'],
        ));
        $target->restore($archive);

        Assert::same($target->table('n')->selectColumn('v'), ['one']);
    }

    #[Test]
    public function backupReturnsPathAsGiven(): void
    {
        $cwd = getcwd();
        Assert::string($cwd);
        chdir($this->root);

        try {
            Assert::same($this->db->backup('rel'), 'rel.tar.gz');
            Assert::true(is_file($this->root . '/rel.tar.gz'));
        } finally {
            chdir($cwd);
        }
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

    /**
     * @return list<string>
     */
    private function tempArtifacts(): array
    {
        return array_merge(
            FsList::glob(sys_get_temp_dir() . '/jp-snapshot-*'),
            FsList::glob(sys_get_temp_dir() . '/jp-restore-*'),
        );
    }

    private static function tmpRoot(): string
    {
        return TempDir::root('jp-backup-hardening-tests');
    }
}
