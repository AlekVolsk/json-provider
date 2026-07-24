<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IssueCategory;
use AV\JsonProvider\Storage\NdjsonStorage;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for primary key integrity: a meta counter that fell behind the
 * data (restored meta.json, hand edit) must never mint a duplicate id —
 * insert re-derives the watermark under its EX lock via the O(1)
 * last-line guard; stored duplicates are a loud validator finding that
 * repair refuses to resolve automatically.
 */
final class PkIdIntegrityTest
{
    private const string DB_PATH = '/tmp/jp-pkid-tests';
    private const string TABLE = 'items';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: ['id' => 'int', 'v' => 'string'],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    #[Test]
    public function rolledBackCounterDoesNotMintDuplicateIds(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->db->insert(self::TABLE, ['v' => 'r' . $i]);
        }

        $metaPath = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($metaPath), true);
        \assert(\is_array($meta) && \is_array($meta[self::TABLE]));
        $meta[self::TABLE]['lastInsertedId'] = 2;
        unlink($metaPath);
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT));

        $id = $this->db->insert(self::TABLE, ['v' => 'fresh']);

        Assert::same($id, 6);

        for ($probe = 1; $probe <= 6; $probe++) {
            Assert::count(
                $this->db->table(self::TABLE)
                    ->where('id', '=', $probe)->selectAllByArray(),
                1,
                'id ' . $probe,
            );
        }
    }

    #[Test]
    public function duplicateStoredIdsAreLoudAndNotAutoRepaired(): void
    {
        $this->db->insert(self::TABLE, ['v' => 'a']);
        $this->db->insert(self::TABLE, ['v' => 'b']);

        file_put_contents(
            $this->dataPath(),
            '{"id":2,"v":"clone"}' . "\n",
            FILE_APPEND,
        );
        $this->db->invalidateCache(self::TABLE);

        $report = $this->db->validateTable(self::TABLE);
        $findings = $report->issuesByCategory(IssueCategory::PK_DUPLICATE);

        Assert::count($findings, 1);
        Assert::same($findings[0]->context['id'], '2');
        Assert::same($findings[0]->context['lines'], '1,2');

        $repairReport = $this->db->repairTable(self::TABLE);
        $repaired = $repairReport->issuesByCategory(
            IssueCategory::PK_DUPLICATE,
        );

        Assert::count($repaired, 1);
        Assert::false($repaired[0]->repaired);
        Assert::notNull($repaired[0]->repairError);

        $clones = $this->db->table(self::TABLE)
            ->where('id', '=', 2)->selectAllByArray();
        Assert::count($clones, 2);
    }

    #[Test]
    public function emptyTableInsertsFromOne(): void
    {
        $storage = new NdjsonStorage($this->dbDir);

        Assert::null($storage->readLastLine(
            self::TABLE,
            self::TABLE . '.ndjson',
        ));

        Assert::same($this->db->insert(self::TABLE, ['v' => 'first']), 1);
    }

    #[Test]
    public function readLastLineReturnsTheLastRecord(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->db->insert(self::TABLE, ['v' => 'r' . $i]);
        }

        $storage = new NdjsonStorage($this->dbDir);
        $last = $storage->readLastLine(self::TABLE, self::TABLE . '.ndjson');

        Assert::notNull($last);
        Assert::same($last['id'], 3);
        Assert::same($last['v'], 'r3');
    }

    #[Test]
    public function readLastLineHandlesLinesLongerThanOneChunk(): void
    {
        $this->db->insert(self::TABLE, ['v' => 'short']);
        $this->db->insert(self::TABLE, ['v' => str_repeat('x', 20000)]);

        $storage = new NdjsonStorage($this->dbDir);
        $last = $storage->readLastLine(self::TABLE, self::TABLE . '.ndjson');

        Assert::notNull($last);
        Assert::same($last['id'], 2);
        Assert::same(\strlen((string)$last['v']), 20000);
    }

    #[Test]
    public function foreignAppendWithHigherIdAdvancesTheSequence(): void
    {
        $this->db->insert(self::TABLE, ['v' => 'a']);

        file_put_contents(
            $this->dataPath(),
            '{"id":41,"v":"foreign"}' . "\n",
            FILE_APPEND,
        );
        $this->db->invalidateCache(self::TABLE);

        $id = $this->db->insert(self::TABLE, ['v' => 'next']);

        Assert::same($id, 42);
        Assert::count(
            $this->db->table(self::TABLE)
                ->where('id', '=', 42)->selectAllByArray(),
            1,
        );
    }

    // -- helpers -----------------------------------------------------------

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
}
