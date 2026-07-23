<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\NdjsonStorage;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the two-tier index degradation contract:
 *
 *  - silent full-scan fallback when the index merely cannot be trusted
 *    (data/meta byteSize desync, pre-v2 format, malformed BETWEEN/IN
 *    bounds) — queries stay correct, no exception;
 *  - loud INDEX_UNRELIABLE when a trusted v2 index is structurally
 *    corrupt (entry count drift, broken line permutation, malformed key)
 *    — wrong rows are never served silently;
 *  - lazy upgrade: the first write on a pre-v2 table rebuilds every index
 *    and stamps indexFormat 2; rebuildAllIndexes/rebuildIndex stamp too,
 *    a single-index rebuild escalating to all on a v1 table;
 *  - eqExists shares the validated-entries path (FK probe primitive).
 */
final class IndexTrustTest
{
    private const string DB_PATH = '/tmp/jp-ixtrust-tests';
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
            uniqueConstraints: [],
            columns: ['id' => 'int', 'grp' => 'int', 'name' => 'string'],
            indexes: [
                new IndexSchema(
                    name: 'idx_grp',
                    fields: [new IndexFieldSchema(
                        'grp',
                        SortDirectionEnum::ASC,
                    )],
                ),
                new IndexSchema(
                    name: 'idx_name',
                    fields: [new IndexFieldSchema(
                        'name',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));

        foreach ([[1, 'a'], [2, 'b'], [1, 'c'], [3, 'd']] as [$grp, $name]) {
            $this->db->insert(
                self::TABLE,
                ['grp' => $grp, 'name' => $name],
            );
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    // -- structural corruption of a trusted index is loud ------------------

    #[Test]
    public function emptiedIndexFileThrowsIndexUnreliable(): void
    {
        file_put_contents($this->indexPath('idx_grp'), '');

        try {
            $this->db->table(self::TABLE)
                ->where('grp', '=', 1)->selectAllByArray();
            Assert::fail('structural corruption must be loud');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INDEX_UNRELIABLE');
            Assert::string($e->getMessage())->contains('idx_grp');
        }
    }

    #[Test]
    public function duplicatedLineReferenceThrowsIndexUnreliable(): void
    {
        $path = $this->indexPath('idx_grp');
        $lines = explode(
            "\n",
            trim((string)file_get_contents($path)),
        );
        $first = json_decode($lines[0], true);
        \assert(\is_array($first));
        $second = json_decode($lines[1], true);
        \assert(\is_array($second));
        $second['line'] = $first['line'];
        $lines[1] = (string)json_encode($second);
        file_put_contents($path, implode("\n", $lines) . "\n");

        try {
            $this->db->table(self::TABLE)
                ->where('grp', '=', 1)->selectAllByArray();
            Assert::fail('permutation break must be loud');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INDEX_UNRELIABLE');
            Assert::string($e->getMessage())->contains('permutation');
        }
    }

    #[Test]
    public function malformedKeyThrowsIndexUnreliable(): void
    {
        $path = $this->indexPath('idx_grp');
        $lines = explode(
            "\n",
            trim((string)file_get_contents($path)),
        );
        $first = json_decode($lines[0], true);
        \assert(\is_array($first));
        $first['key'] = 'zz';
        $lines[0] = (string)json_encode($first);
        file_put_contents($path, implode("\n", $lines) . "\n");

        try {
            $this->db->table(self::TABLE)
                ->where('grp', '=', 1)->selectAllByArray();
            Assert::fail('malformed key must be loud');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INDEX_UNRELIABLE');
            Assert::string($e->getMessage())->contains('malformed');
        }
    }

    // -- mistrust degrades silently ----------------------------------------

    #[Test]
    public function byteSizeDesyncDegradesToFullScanSilently(): void
    {
        file_put_contents(
            $this->dbDir . '/items/items.ndjson',
            '{"id":99,"grp":1,"name":"raw"}' . "\n",
            FILE_APPEND,
        );
        $this->db->invalidateCache(self::TABLE);

        $rows = $this->db->table(self::TABLE)
            ->where('grp', '=', 1)->selectAllByArray();

        Assert::count($rows, 3);
        Assert::same(array_column($rows, 'id'), [1, 3, 99]);
    }

    #[Test]
    public function betweenWithNullBoundIsRejectedBeforeExecution(): void
    {
        try {
            $this->db->table(self::TABLE)
                ->where('grp', 'BETWEEN', [null, 2])->selectAllByArray();
            Assert::fail('a null BETWEEN bound must be rejected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'CONDITION_MALFORMED');
        }
    }

    #[Test]
    public function inEmptyListIsAuthoritativeZero(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('grp', 'IN', [])->selectAllByArray();

        Assert::count($rows, 0);
    }

    #[Test]
    public function nonFiniteConditionIsRejectedBeforeExecution(): void
    {
        foreach ([NAN, INF, -INF] as $value) {
            try {
                $this->db->table(self::TABLE)
                    ->where('grp', '=', $value)->selectAllByArray();
                Assert::fail('a non-finite condition must be rejected');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'CONDITION_TYPE_MISMATCH');
            }
        }
    }

    #[Test]
    public function singleElementBetweenIsRejectedBeforeExecution(): void
    {
        try {
            $this->db->table(self::TABLE)
                ->where('grp', 'BETWEEN', [1])->selectAllByArray();
            Assert::fail('a one-element BETWEEN must be rejected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'CONDITION_MALFORMED');
        }
    }

    // -- lazy v1 -> v2 upgrade ---------------------------------------------

    #[Test]
    public function legacyFormatDegradesToFullScanAndInsertHeals(): void
    {
        $this->dropIndexFormatMarker();
        file_put_contents($this->indexPath('idx_grp'), "garbage\n");

        $rows = $this->db->table(self::TABLE)
            ->where('grp', '=', 1)->selectAllByArray();
        Assert::count($rows, 2);

        $this->db->insert(self::TABLE, ['grp' => 1, 'name' => 'e']);

        Assert::same($this->metaIndexFormat(), 2);

        $rows = $this->db->table(self::TABLE)
            ->where('grp', '=', 1)->selectAllByArray();
        Assert::count($rows, 3);
    }

    #[Test]
    public function rebuildAllIndexesStampsFormat(): void
    {
        $this->dropIndexFormatMarker();

        $this->db->table(self::TABLE)->rebuildAllIndexes();

        Assert::same($this->metaIndexFormat(), 2);
    }

    #[Test]
    public function singleIndexRebuildOnV1EscalatesToAll(): void
    {
        $this->dropIndexFormatMarker();
        file_put_contents($this->indexPath('idx_grp'), "garbage\n");
        file_put_contents($this->indexPath('idx_name'), "garbage\n");

        $this->db->table(self::TABLE)->rebuildIndex('idx_grp');

        Assert::same($this->metaIndexFormat(), 2);

        Assert::count(
            $this->db->table(self::TABLE)
                ->where('grp', '=', 1)->selectAllByArray(),
            2,
        );
        Assert::count(
            $this->db->table(self::TABLE)
                ->where('name', '=', 'b')->selectAllByArray(),
            1,
        );
    }

    // -- eqExists probe primitive ------------------------------------------

    #[Test]
    public function eqExistsSeesCommittedEntries(): void
    {
        $manager = new IndexManager(new NdjsonStorage($this->dbDir));
        $index = new IndexSchema(
            name: 'idx_grp',
            fields: [new IndexFieldSchema('grp', SortDirectionEnum::ASC)],
        );

        $entries = $manager->readIndexValidated(self::TABLE, $index, 4, true);
        Assert::notNull($entries);

        Assert::true($manager->eqExists($index, $entries, 1));
        Assert::true($manager->eqExists($index, $entries, 3));
        Assert::true(!$manager->eqExists($index, $entries, 99));

        $this->db->insert(self::TABLE, ['grp' => 99, 'name' => 'fresh']);

        $fresh = $manager->readIndexValidated(self::TABLE, $index, 5, true);
        Assert::notNull($fresh);
        Assert::true($manager->eqExists($index, $fresh, 99));
    }

    // -- helpers -----------------------------------------------------------

    private function indexPath(string $indexName): string
    {
        return $this->dbDir . '/' . self::TABLE . '/' . $indexName
            . '.index.ndjson';
    }

    private function dropIndexFormatMarker(): void
    {
        $path = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($path), true);
        \assert(\is_array($meta) && \is_array($meta[self::TABLE]));
        unset($meta[self::TABLE]['indexFormat']);
        file_put_contents($path, json_encode($meta));
    }

    private function metaIndexFormat(): int | null
    {
        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        \assert(\is_array($meta) && \is_array($meta[self::TABLE]));

        $format = $meta[self::TABLE]['indexFormat'] ?? null;

        return \is_int($format) ? $format : null;
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
