<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the non-finite float contract: NAN, INF and -INF are rejected
 * with NON_FINITE_FLOAT (naming the table and column) before any disk
 * write, symmetrically on insert and update; every finite float — including
 * -0.0 and the extreme representable values — passes.
 */
final class NonFiniteFloatTest
{
    private const string TABLE = 'measures';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            uniqueConstraints: [],
            columns: [
                'id'  => 'int',
                'f'   => 'float',
                'opt' => 'float|null',
            ],
            indexes: [],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function insertInfRejected(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('NAN and INF');

        $this->db->insert(self::TABLE, ['f' => INF]);
    }

    #[Test]
    public function insertNegativeInfRejected(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('NAN and INF');

        $this->db->insert(self::TABLE, ['f' => -INF]);
    }

    #[Test]
    public function insertNanRejected(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('NAN and INF');

        $this->db->insert(self::TABLE, ['f' => NAN]);
    }

    #[Test]
    public function nonFiniteRejectedOnNullableColumnToo(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('NAN and INF');

        $this->db->insert(self::TABLE, ['f' => 1.0, 'opt' => INF]);
    }

    #[Test]
    public function errorKeyIsNonFiniteFloat(): void
    {
        try {
            $this->db->insert(self::TABLE, ['f' => NAN]);
            Assert::fail('a StorageException was expected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'NON_FINITE_FLOAT');
            Assert::string($e->getMessage())->contains(self::TABLE);
            Assert::string($e->getMessage())->contains('"f"');
        }
    }

    #[Test]
    public function updateNonFiniteRejectedBeforeAnyWrite(): void
    {
        $id = $this->db->insert(self::TABLE, ['f' => 1.5]);
        $before = md5((string)file_get_contents($this->dataPath()));

        try {
            $this->db->table(self::TABLE)
                ->where('id', '=', $id)
                ->updateByArray(['f' => INF]);
            Assert::fail('a StorageException was expected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'NON_FINITE_FLOAT');
        }

        $after = md5((string)file_get_contents($this->dataPath()));
        Assert::same($after, $before);
    }

    #[Test]
    public function finiteEdgeValuesPass(): void
    {
        $values = [0.0, -0.0, 1.5, PHP_FLOAT_MAX, -PHP_FLOAT_MAX,
            PHP_FLOAT_MIN, PHP_FLOAT_EPSILON];

        foreach ($values as $value) {
            $id = $this->db->insert(self::TABLE, ['f' => $value]);
            Assert::int($id)->greaterThan(0);
        }

        Assert::same(
            $this->db->table(self::TABLE)->count(),
            \count($values),
        );
    }

    #[Test]
    public function intWideningIntoFloatColumnStillWorks(): void
    {
        $id = $this->db->insert(self::TABLE, ['f' => 3]);

        $this->db->invalidateCache(self::TABLE);
        $row = $this->db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();

        Assert::notNull($row);
        Assert::same($row['f'], 3.0);
    }

    #[Test]
    public function nullOnNullableFloatColumnPasses(): void
    {
        $id = $this->db->insert(self::TABLE, ['f' => 1.0, 'opt' => null]);

        $row = $this->db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();

        Assert::notNull($row);
        Assert::null($row['opt']);
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
        return TempDir::root('jp-nonfinite-tests');
    }
}
