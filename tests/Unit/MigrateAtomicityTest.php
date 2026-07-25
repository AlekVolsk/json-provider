<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\InMemoryCache;
use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IssueSeverity;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the migrateColumns crash model: the full migrated record set
 * is encode-probed before the schema or any file is touched; the schema is
 * published before the data rewrite so repair converges a crash window to
 * the migration's target state (added not-null columns back-filled with
 * their type defaults, not null); and the rewrite is always based on the
 * on-disk state, never the cache.
 */
final class MigrateAtomicityTest
{
    private const string TABLE = 'goods';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase(
            $this->dbDir,
            new InMemoryCache(),
        );

        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: ['id' => 'int', 'price' => 'float'],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function unencodableStoredValueAbortsWithNothingWritten(): void
    {
        $this->db->insert(self::TABLE, ['price' => 10000.5]);
        $this->db->insert(self::TABLE, ['price' => 2.5]);

        $dataPath = $this->dataPath();
        $tampered = str_replace(
            '"price":10000.5',
            '"price":1.0e999',
            (string)file_get_contents($dataPath),
        );
        file_put_contents($dataPath, $tampered);

        $schemaBefore = file_get_contents(
            $this->dbDir . '/information_schema.json',
        );
        $pkBefore = file_get_contents(
            $this->dbDir . '/' . self::TABLE . '/pk.index.ndjson',
        );

        try {
            $this->db->migrateColumns(TableSchema::create(
                name: self::TABLE,
                columns: [
                    'id'    => 'int',
                    'price' => 'float',
                    'note'  => 'string',
                ],
            ));
            Assert::fail('an unencodable stored value must abort the migrate');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_RECORD');
        }

        Assert::same(file_get_contents($dataPath), $tampered);
        Assert::same(
            file_get_contents($this->dbDir . '/information_schema.json'),
            $schemaBefore,
        );
        Assert::same(
            file_get_contents(
                $this->dbDir . '/' . self::TABLE . '/pk.index.ndjson',
            ),
            $pkBefore,
        );
        Assert::same($this->db->columnNames(self::TABLE), ['id', 'price']);
    }

    #[Test]
    public function crashBetweenSchemaAndDataHealsToTargetState(): void
    {
        $this->db->insert(self::TABLE, ['price' => 1.5]);
        $this->db->insert(self::TABLE, ['price' => 2.5]);

        $path = $this->dbDir . '/information_schema.json';
        $data = json_decode((string)file_get_contents($path), true);
        \assert(\is_array($data) && \is_array($data['tables']));
        $table = $data['tables'][self::TABLE];
        \assert(\is_array($table) && \is_array($table['columns']));
        $table['columns']['qty'] = 'int';
        $data['tables'][self::TABLE] = $table;
        unlink($path);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

        $report = $this->db->validate();
        Assert::int(\count($report->issuesBySeverity(
            IssueSeverity::WARNING,
        )) + \count($report->issuesBySeverity(
            IssueSeverity::ERROR,
        )))->greaterThan(0);

        $this->db->repair();

        foreach ($this->db->readAll(self::TABLE) as $record) {
            Assert::same($record['qty'], 0);
        }
    }

    #[Test]
    public function repairRefusesToInventMissingTemporalValues(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'events',
            columns: ['id' => 'int', 'at' => 'datetime'],
        ));
        $this->db->insert(
            'events',
            ['at' => '2026-01-01 10:00:00'],
        );

        $path = $this->dbDir . '/events/events.ndjson';
        file_put_contents($path, '{"id":2}' . "\n", FILE_APPEND);
        $this->db->invalidateCache('events');

        $before = file_get_contents($path);
        $report = $this->db->repairTable('events');

        $failed = false;

        foreach ($report->issues as $issue) {
            if ($issue->repairError !== null) {
                $failed = true;
            }
        }

        Assert::true($failed);
        Assert::same(file_get_contents($path), $before);
    }

    #[Test]
    public function rewriteIsBasedOnDiskNotOnStaleCache(): void
    {
        $this->db->insert(self::TABLE, ['price' => 1.5]);
        $this->db->table(self::TABLE)->selectAllByArray();

        file_put_contents(
            $this->dataPath(),
            '{"id":2,"price":9.5}' . "\n",
            FILE_APPEND,
        );

        $this->db->migrateColumns(TableSchema::create(
            name: self::TABLE,
            columns: [
                'id'    => 'int',
                'price' => 'float',
                'note'  => 'string',
            ],
        ));

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::count($rows, 2);
        Assert::same($rows[1]['price'], 9.5);
        Assert::same($rows[1]['note'], '');
    }

    private function dataPath(): string
    {
        return $this->dbDir . '/' . self::TABLE . '/' . self::TABLE
            . '.ndjson';
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
        return TempDir::root('jp-migrate-tests');
    }
}
