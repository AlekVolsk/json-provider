<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for type-distinguishing distinct keys: null, '', false, 0, '0',
 * true, 1 and '1' are eight different values and all survive
 * isDistinct(); real duplicates collapse keeping the first occurrence;
 * composite distinct distinguishes per-combination.
 *
 * The schema boundary rejects unknown column types, so one column can hold
 * mixed scalar types only through foreign tampering with the data file —
 * the mixed-shape fixtures are written to the NDJSON file directly, and
 * distinct must still keep them apart when reading that anomaly.
 */
final class DistinctTypingTest
{
    private const string DB_PATH = '/tmp/jp-distinct-tests';
    private const string TABLE = 'mixed';

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
            columns: [
                'id' => 'int',
                'v'  => 'string|null',
                'w'  => 'string|null',
            ],
            indexes: [],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    #[Test]
    public function eightScalarShapesSurviveDistinct(): void
    {
        $values = [null, '', false, 0, '0', true, 1, '1'];

        $this->injectRows(array_map(
            static fn (mixed $v): array => ['v' => $v, 'w' => null],
            $values,
        ));

        $rows = $this->db->table(self::TABLE)
            ->isDistinct('v')->selectAllByArray();

        Assert::count($rows, \count($values));
    }

    #[Test]
    public function realDuplicatesCollapseKeepingFirstOccurrence(): void
    {
        foreach (['a', 'b', 'a', 'c', 'b'] as $v) {
            $this->db->insert(self::TABLE, ['v' => $v, 'w' => null]);
        }

        $rows = $this->db->table(self::TABLE)
            ->isDistinct('v')->selectAllByArray();

        Assert::same(array_column($rows, 'v'), ['a', 'b', 'c']);
        Assert::same(array_column($rows, 'id'), [1, 2, 4]);
    }

    #[Test]
    public function compositeDistinctDistinguishesPerCombination(): void
    {
        $pairs = [
            [0, '0'],
            ['0', 0],
            [0, 0],
            ['0', '0'],
            [0, '0'],
        ];

        $this->injectRows(array_map(
            static fn (array $p): array => ['v' => $p[0], 'w' => $p[1]],
            $pairs,
        ));

        $rows = $this->db->table(self::TABLE)
            ->isDistinct('v', 'w')->selectAllByArray();

        Assert::count($rows, 4);
        Assert::same(array_column($rows, 'id'), [1, 2, 3, 4]);
    }

    #[Test]
    public function distinctWithNulBytesInValuesDoesNotCollideTuples(): void
    {
        $this->db->insert(self::TABLE, ['v' => "a\x00b", 'w' => 'c']);
        $this->db->insert(self::TABLE, ['v' => 'a', 'w' => "b\x00c"]);

        $rows = $this->db->table(self::TABLE)
            ->isDistinct('v', 'w')->selectAllByArray();

        Assert::count($rows, 2);
    }

    // -- helpers -----------------------------------------------------------

    /**
     * Writes rows straight into the table's NDJSON file (bypassing the
     * typed write API) — the only way a column can end up holding mixed
     * scalar types.
     *
     * @param array<int,array<string,null|scalar>> $rows
     */
    private function injectRows(array $rows): void
    {
        $lines = '';
        $id = 0;

        foreach ($rows as $row) {
            $id++;
            $lines .= json_encode(
                ['id' => $id] + $row,
                JSON_PRESERVE_ZERO_FRACTION,
            ) . "\n";
        }

        file_put_contents(
            $this->dbDir . '/' . self::TABLE . '/' . self::TABLE . '.ndjson',
            $lines,
        );
        $this->db->invalidateCache(self::TABLE);
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
