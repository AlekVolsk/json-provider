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
                'v'  => 'variant|null',
                'w'  => 'variant|null',
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

        foreach ($values as $v) {
            $this->db->insert(self::TABLE, ['v' => $v, 'w' => null]);
        }

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

        foreach ($pairs as [$v, $w]) {
            $this->db->insert(self::TABLE, ['v' => $v, 'w' => $w]);
        }

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
