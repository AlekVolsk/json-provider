<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\InMemoryCache;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Tests\Support\CacheKeys;
use AV\JsonProvider\Tests\Support\Dto\FloatPriceDto;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the float JSON roundtrip contract:
 *
 *  - write side: fresh writes keep the zero fraction on disk
 *    (JSON_PRESERVE_ZERO_FRACTION on append, rewrite and the insert probe),
 *    so 99.0 is stored as "99.0" and decodes back as PHP float;
 *  - read side: legacy rows written without the fraction ("price":99)
 *    are widened to float on every read path — full scan, indexed select,
 *    count, cache fill, DTO hydration — so strict `=`/IN comparisons and
 *    distinct treat them exactly like fresh float rows;
 *  - cache symmetry: every cache payload holds the widened types, so a
 *    cache hit is indistinguishable from a cold disk read.
 */
final class FloatRoundtripTest
{
    private const string TABLE = 'floats';

    private string $dbDir;

    private JsonDataProvider $db;

    private InMemoryCache $cache;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->cache = new InMemoryCache();
        $this->db = JsonDataProvider::createDatabase(
            $this->dbDir,
            $this->cache,
        );

        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            uniqueConstraints: [],
            columns: [
                'id'    => 'int',
                'name'  => 'string',
                'price' => 'float',
            ],
            indexes: [
                new IndexSchema(
                    name: 'idx_price',
                    fields: [new IndexFieldSchema(
                        'price',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function insertAppendKeepsZeroFractionOnDisk(): void
    {
        $this->db->insert(self::TABLE, ['name' => 'a', 'price' => 99.0]);

        Assert::string($this->rawDataFile())->contains('"price":99.0');
    }

    #[Test]
    public function fullRewriteKeepsZeroFractionOnDisk(): void
    {
        $this->db->insert(self::TABLE, ['name' => 'a', 'price' => 99.0]);
        $this->db->table(self::TABLE)
            ->where('name', '=', 'a')
            ->updateByArray(['name' => 'b']);

        Assert::string($this->rawDataFile())->contains('"price":99.0');
    }

    #[Test]
    public function ndjsonAppendWritesZeroFraction(): void
    {
        $dir = self::dbPathRoot() . '/' . uniqid('raw', true);
        mkdir($dir . '/t', 0755, true);
        touch($dir . '/t/t.ndjson');
        $storage = new NdjsonStorage($dir);

        $storage->append('t', 't.ndjson', ['id' => 1, 'price' => 99.0]);

        $raw = file_get_contents($dir . '/t/t.ndjson');
        Assert::string((string)$raw)->contains('"price":99.0');
    }

    #[Test]
    public function negativeZeroRoundtripsWithSign(): void
    {
        $id = $this->db->insert(
            self::TABLE,
            ['name' => 'z', 'price' => -0.0],
        );

        Assert::string($this->rawDataFile())->contains('"price":-0.0');

        $this->db->invalidateCache(self::TABLE);
        $row = $this->db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::true(\is_float($row['price']));
        Assert::same((string)$row['price'], '-0');
    }

    #[Test]
    public function equalsFloatConditionFindsFreshRow(): void
    {
        $this->db->insert(self::TABLE, ['name' => 'a', 'price' => 99.0]);
        $this->db->invalidateCache(self::TABLE);

        $rows = $this->db->table(self::TABLE)
            ->where('price', '=', 99.0)->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['price'], 99.0);
    }

    #[Test]
    public function inFloatConditionFindsFreshRow(): void
    {
        $this->db->insert(self::TABLE, ['name' => 'a', 'price' => 99.0]);
        $this->db->invalidateCache(self::TABLE);

        $rows = $this->db->table(self::TABLE)
            ->where('price', 'IN', [99.0])->selectAllByArray();

        Assert::count($rows, 1);
    }

    #[Test]
    public function legacyIntRowSurfacesAsFloatOnFullScan(): void
    {
        $this->insertLegacyIntRow(99);

        $rows = $this->db->table(self::TABLE)->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['price'], 99.0);
    }

    #[Test]
    public function legacyIntRowIsFoundByFloatEquals(): void
    {
        $this->insertLegacyIntRow(99);

        $rows = $this->db->table(self::TABLE)
            ->where('price', '=', 99.0)->selectAllByArray();

        Assert::count($rows, 1);
    }

    #[Test]
    public function legacyIntRowIsCountedByFloatCondition(): void
    {
        $this->insertLegacyIntRow(99);

        $count = $this->db->table(self::TABLE)
            ->where('price', '=', 99.0)->count();

        Assert::same($count, 1);
    }

    #[Test]
    public function legacyNegativeZeroWidensWithoutSign(): void
    {
        $this->db->insert(self::TABLE, ['name' => 'z', 'price' => -0.0]);
        $this->patchDataFile('"price":-0.0', '"price":-0');

        $rows = $this->db->table(self::TABLE)->selectAllByArray();

        Assert::count($rows, 1);
        Assert::true(\is_float($rows[0]['price']));
        Assert::same((string)$rows[0]['price'], '0');
    }

    #[Test]
    public function orderingIndexPathReturnsWidenedFloats(): void
    {
        $this->insertLegacyIntRow(99);

        $rows = $this->db->table(self::TABLE)
            ->orderBy('price')->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['price'], 99.0);
    }

    #[Test]
    public function orderingIndexWithConditionReturnsWidenedFloats(): void
    {
        $this->insertLegacyIntRow(99);

        $rows = $this->db->table(self::TABLE)
            ->where('price', '=', 99.0)
            ->orderBy('price')
            ->limit(5)
            ->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['price'], 99.0);
    }

    #[Test]
    public function rebuildIndexReadsWidenedFloats(): void
    {
        $this->insertLegacyIntRow(99);
        $this->db->table(self::TABLE)->rebuildAllIndexes();

        $rows = $this->db->table(self::TABLE)
            ->where('price', '=', 99.0)
            ->orderBy('price')
            ->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['price'], 99.0);
    }

    #[Test]
    public function distinctDoesNotSplitLegacyIntAndFreshFloat(): void
    {
        $this->db->insert(self::TABLE, ['name' => 'a', 'price' => 99.0]);
        $this->appendRawLine(['id' => 90, 'name' => 'b', 'price' => 99]);

        $rows = $this->db->table(self::TABLE)
            ->distinct('price')->selectAllByArray();

        Assert::count($rows, 1);
    }

    #[Test]
    public function updateRewriteNormalizesLegacyIntOnDisk(): void
    {
        $this->insertLegacyIntRow(99);

        $this->db->table(self::TABLE)
            ->where('price', '=', 99.0)
            ->updateByArray(['name' => 'renamed']);

        Assert::string($this->rawDataFile())->contains('"price":99.0');
    }

    #[Test]
    public function optimizeTableMigratesLegacyIntToZeroFraction(): void
    {
        $this->insertLegacyIntRow(99);
        Assert::string($this->rawDataFile())->contains('"price":99');

        $this->db->table(self::TABLE)->optimizeTable();

        Assert::string($this->rawDataFile())->contains('"price":99.0');
    }

    #[Test]
    public function cacheFillOnReadStoresWidenedFloats(): void
    {
        $this->insertLegacyIntRow(99);

        $this->db->table(self::TABLE)->selectAllByArray();

        $cached = $this->cache->get(
            CacheKeys::current($this->dbDir, self::TABLE),
        );
        Assert::notNull($cached);
        Assert::same($cached[0]['price'], 99.0);
    }

    #[Test]
    public function cacheHitServesWidenedFloats(): void
    {
        $this->insertLegacyIntRow(99);

        $this->db->table(self::TABLE)->selectAllByArray();
        $rows = $this->db->table(self::TABLE)
            ->where('price', '=', 99.0)->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['price'], 99.0);
    }

    #[Test]
    public function writeAllCachePayloadHoldsFloats(): void
    {
        $this->db->insert(self::TABLE, ['name' => 'a', 'price' => 99.0]);
        $this->db->table(self::TABLE)
            ->where('name', '=', 'a')
            ->updateByArray(['name' => 'b']);

        $cached = $this->cache->get(
            CacheKeys::current($this->dbDir, self::TABLE),
        );
        Assert::notNull($cached);
        Assert::same($cached[0]['price'], 99.0);
    }

    #[Test]
    public function dtoAndArraySelectAgreeOnFloatType(): void
    {
        $this->insertLegacyIntRow(99);
        $this->db->registerDto(FloatPriceDto::class);

        $array = $this->db->table(self::TABLE)->selectOneByArray();
        $dto = $this->db->table(self::TABLE)->selectOne();

        Assert::notNull($array);
        Assert::same($array['price'], 99.0);
        Assert::true($dto instanceof FloatPriceDto);
        Assert::same($dto->price, 99.0);
    }

    #[Test]
    public function dtoRoundtripKeepsFloatType(): void
    {
        $this->db->registerDto(FloatPriceDto::class);
        $id = $this->db->table(self::TABLE)
            ->insert(new FloatPriceDto(0, 'fresh', 5.0));

        $this->db->invalidateCache(self::TABLE);
        $dto = $this->db->table(self::TABLE)
            ->where('id', '=', $id)->selectOne();

        Assert::true($dto instanceof FloatPriceDto);
        Assert::same($dto->price, 5.0);
    }

    /**
     * Plants a single legacy-format row whose float column holds a bare
     * JSON int (pre-zero-fraction writer), then drops the cache so the
     * next read is cold. Written through the provider first so meta and
     * indexes are committed consistently, then the data file is patched
     * in place byte-for-byte (same length is not required by any reader:
     * only write paths compare sizes).
     */
    private function insertLegacyIntRow(int $price): void
    {
        $this->db->insert(
            self::TABLE,
            ['name' => 'legacy', 'price' => (float)$price],
        );

        $this->patchDataFile(
            '"price":' . \sprintf('%.1F', (float)$price),
            '"price":' . $price,
        );
    }

    /**
     * Byte-patches the data file in place (models a legacy or external
     * writer) and drops the cache so the next read is cold.
     */
    private function patchDataFile(string $search, string $replace): void
    {
        $path = $this->dataPath();
        $raw = file_get_contents($path);
        \assert($raw !== false);

        $patched = str_replace($search, $replace, $raw);
        \assert($patched !== $raw);
        file_put_contents($path, $patched);

        $this->db->invalidateCache(self::TABLE);
    }

    /**
     * Appends a raw NDJSON line bypassing the provider (no meta commit) —
     * models an external writer; the next write operation heals via the
     * byteSize gate, read paths just see the extra line.
     *
     * @param array<string,null|scalar> $record
     */
    private function appendRawLine(array $record): void
    {
        file_put_contents(
            $this->dataPath(),
            json_encode($record) . "\n",
            FILE_APPEND,
        );
        $this->db->invalidateCache(self::TABLE);
    }

    private function rawDataFile(): string
    {
        $raw = file_get_contents($this->dataPath());

        return $raw === false ? '' : $raw;
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
        return TempDir::root('jp-float-tests');
    }
}
