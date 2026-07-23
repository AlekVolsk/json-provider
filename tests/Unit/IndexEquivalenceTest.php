<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Index-vs-full-scan equivalence tests: every query against an indexed
 * table must return exactly what the same query returns against a twin
 * table with no indexes. Covers DESC range mirroring, composite
 * first-component search, string ranges (bytewise), pagination through an
 * ordering index, and distinct excluding index-side pagination.
 */
final class IndexEquivalenceTest
{
    private const string DB_PATH = '/tmp/jp-ixequiv-tests';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $columns = [
            'id'    => 'int',
            'grp'   => 'int',
            'val'   => 'int',
            'price' => 'float',
            'name'  => 'string',
        ];

        $this->db->createTable(TableSchema::create(
            name: 'indexed',
            uniqueConstraints: [],
            columns: $columns,
            indexes: [
                new IndexSchema(
                    name: 'idx_price_desc',
                    fields: [new IndexFieldSchema(
                        'price',
                        SortDirectionEnum::DESC,
                    )],
                ),
                new IndexSchema(
                    name: 'idx_grp_val',
                    fields: [
                        new IndexFieldSchema('grp', SortDirectionEnum::ASC),
                        new IndexFieldSchema('val', SortDirectionEnum::DESC),
                    ],
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

        $this->db->createTable(TableSchema::create(
            name: 'twin',
            uniqueConstraints: [],
            columns: $columns,
            indexes: [],
        ));

        $names = ['9', '10', '100', 'a', 'ab', 'b', '9', '10', 'a', 'b'];

        for ($i = 0; $i < 20; $i++) {
            $row = [
                'grp'   => $i % 4,
                'val'   => ($i * 7) % 10,
                'price' => (($i * 13) % 7) + ($i % 2 === 0 ? 0.5 : 0.0),
                'name'  => $names[$i % 10],
            ];
            $this->db->insert('indexed', $row);
            $this->db->insert('twin', $row);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    // -- DESC range mirroring (ix-desc-range-swap) -------------------------

    #[Test]
    public function descIndexRangeOperatorsMatchFullScan(): void
    {
        $cases = [
            ['price', '>', 2.5],
            ['price', '>=', 2.5],
            ['price', '<', 4.5],
            ['price', '<=', 4.5],
            ['price', '>', 0.0],
            ['price', '<', 0.5],
            ['price', '>=', 6.5],
            ['price', 'BETWEEN', [1.5, 4.5]],
            ['price', 'BETWEEN', [0.5, 0.5]],
        ];

        foreach ($cases as [$field, $op, $value]) {
            $this->assertSameIds($field, $op, $value);
        }
    }

    #[Test]
    public function descIndexEqAndInMatchFullScan(): void
    {
        $this->assertSameIds('price', '=', 2.5);
        $this->assertSameIds('price', 'IN', [0.5, 4.0, 99.0]);
    }

    // -- composite first-component search (ix-composite-eq-prefix) ---------

    #[Test]
    public function compositeEqOnFirstFieldMatchesFullScan(): void
    {
        $this->assertSameIds('grp', '=', 2);
        $this->assertSameIds('grp', '=', 99);
    }

    #[Test]
    public function compositeInOnFirstFieldMatchesFullScan(): void
    {
        $this->assertSameIds('grp', 'IN', [1, 3]);
        $this->assertSameIds('grp', 'IN', []);
    }

    #[Test]
    public function compositeRangeOnFirstFieldMatchesFullScan(): void
    {
        $this->assertSameIds('grp', '>', 1);
        $this->assertSameIds('grp', '<=', 2);
        $this->assertSameIds('grp', 'BETWEEN', [1, 2]);
    }

    #[Test]
    public function conditionOnSecondCompositeFieldIsCorrect(): void
    {
        $this->assertSameIds('val', '=', 3);
        $this->assertSameIds('val', '>', 5);
    }

    // -- string ranges are bytewise and index-served (Binary) --------------

    #[Test]
    public function stringRangeViaIndexMatchesFullScan(): void
    {
        $this->assertSameIds('name', '>', '10');
        $this->assertSameIds('name', '>=', '9');
        $this->assertSameIds('name', '<', 'ab');
        $this->assertSameIds('name', 'BETWEEN', ['10', 'a']);
        $this->assertSameIds('name', '=', '9');
        $this->assertSameIds('name', 'IN', ['9', 'b', 'zz']);
    }

    #[Test]
    public function stringRangeAgreesWithOrderBy(): void
    {
        $ordered = array_column(
            $this->db->table('indexed')->orderBy('name')->selectAllByArray(),
            'name',
        );

        $above = $this->db->table('indexed')
            ->where('name', '>', '10')->selectAllByArray();

        $expected = array_values(array_filter(
            $ordered,
            static fn (mixed $n): bool => \is_string($n)
                && strcmp($n, '10') > 0,
        ));
        $actual = array_column($above, 'name');
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        Assert::same($actual, $expected);
    }

    // -- pagination through the ordering index -----------------------------

    #[Test]
    public function orderingIndexPaginationMatchesFullScanSlice(): void
    {
        foreach ([[0, 5], [5, 5], [18, 5], [0, null], [3, null]] as $case) {
            [$offset, $limit] = $case;

            $viaIndex = $this->db->table('indexed')
                ->orderBy('price', 'desc')
                ->offset($offset);
            $viaTwin = $this->db->table('twin')
                ->orderBy('price', 'desc')
                ->offset($offset);

            if ($limit !== null) {
                $viaIndex->limit($limit);
                $viaTwin->limit($limit);
            }

            Assert::same(
                array_column($viaIndex->selectAllByArray(), 'id'),
                array_column($viaTwin->selectAllByArray(), 'id'),
                "offset {$offset}",
            );
        }
    }

    // -- distinct never paginates inside the index path --------------------

    #[Test]
    public function distinctWithOrderingIndexPaginatesAfterDedup(): void
    {
        $pages = [];

        foreach ([0, 2, 4, 6] as $offset) {
            $page = $this->db->table('indexed')
                ->isDistinct('price')
                ->orderBy('price', 'desc')
                ->limit(2)
                ->offset($offset)
                ->selectAllByArray();

            $twinPage = $this->db->table('twin')
                ->isDistinct('price')
                ->orderBy('price', 'desc')
                ->limit(2)
                ->offset($offset)
                ->selectAllByArray();

            Assert::same(
                array_column($page, 'price'),
                array_column($twinPage, 'price'),
                "offset {$offset}",
            );

            foreach ($page as $row) {
                $pages[] = $row['price'];
            }
        }

        $full = array_column(
            $this->db->table('twin')
                ->isDistinct('price')
                ->orderBy('price', 'desc')
                ->selectAllByArray(),
            'price',
        );

        Assert::same($pages, \array_slice($full, 0, \count($pages)));
    }

    // -- audit regressions -------------------------------------------------

    #[Test]
    public function bigIntBoundaryRangesMatchFullScan(): void
    {
        $columns = ['id' => 'int', 'n' => 'int'];

        $this->db->createTable(TableSchema::create(
            name: 'big',
            uniqueConstraints: [],
            columns: $columns,
            indexes: [
                new IndexSchema(
                    name: 'idx_n',
                    fields: [new IndexFieldSchema(
                        'n',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'bigtwin',
            uniqueConstraints: [],
            columns: $columns,
            indexes: [],
        ));

        $values = [2 ** 53 + 1, 2 ** 53, 2 ** 53 - 1, PHP_INT_MAX, 1];

        foreach ($values as $n) {
            $this->db->insert('big', ['n' => $n]);
            $this->db->insert('bigtwin', ['n' => $n]);
        }

        $cases = [
            ['<=', (float)(2 ** 53)],
            ['<', (float)(2 ** 53)],
            ['>', 2 ** 53],
            ['>=', 2 ** 53 + 1],
            ['<=', 2 ** 53],
            ['BETWEEN', [(float)(2 ** 53), (float)(2 ** 53)]],
            ['BETWEEN', [2 ** 53, PHP_INT_MAX]],
            ['>', (float)PHP_INT_MAX],
        ];

        foreach ($cases as [$op, $value]) {
            $viaIndex = array_column(
                $this->db->table('big')
                    ->where('n', $op, $value)->selectAllByArray(),
                'id',
            );
            $viaTwin = array_column(
                $this->db->table('bigtwin')
                    ->where('n', $op, $value)->selectAllByArray(),
                'id',
            );
            sort($viaIndex);
            sort($viaTwin);

            Assert::same(
                $viaIndex,
                $viaTwin,
                "n {$op} " . var_export($value, true),
            );
        }
    }

    #[Test]
    public function passthroughColumnRangesMatchFullScan(): void
    {
        $columns = ['id' => 'int', 'v' => 'variant'];

        $this->db->createTable(TableSchema::create(
            name: 'vart',
            uniqueConstraints: [],
            columns: $columns,
            indexes: [
                new IndexSchema(
                    name: 'idx_v',
                    fields: [new IndexFieldSchema(
                        'v',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'varttwin',
            uniqueConstraints: [],
            columns: $columns,
            indexes: [],
        ));

        foreach ([7, true, '5', 0.5] as $v) {
            $this->db->insert('vart', ['v' => $v]);
            $this->db->insert('varttwin', ['v' => $v]);
        }

        $cases = [
            ['>', '5'],
            ['>', 0],
            ['<=', true],
            ['BETWEEN', [0, 10]],
            ['=', 7],
            ['IN', [true, '5']],
        ];

        foreach ($cases as [$op, $value]) {
            $viaIndex = array_column(
                $this->db->table('vart')
                    ->where('v', $op, $value)->selectAllByArray(),
                'id',
            );
            $viaTwin = array_column(
                $this->db->table('varttwin')
                    ->where('v', $op, $value)->selectAllByArray(),
                'id',
            );
            sort($viaIndex);
            sort($viaTwin);

            Assert::same(
                $viaIndex,
                $viaTwin,
                "v {$op} " . var_export($value, true),
            );
        }
    }

    #[Test]
    public function unorderedIndexedSelectReturnsFileOrder(): void
    {
        $viaIndex = array_column(
            $this->db->table('indexed')
                ->where('grp', '>', 0)->selectAllByArray(),
            'id',
        );
        $viaTwin = array_column(
            $this->db->table('twin')
                ->where('grp', '>', 0)->selectAllByArray(),
            'id',
        );

        Assert::same($viaIndex, $viaTwin);

        $sorted = $viaIndex;
        sort($sorted);
        Assert::same($viaIndex, $sorted, 'file order is ascending-id here');
    }

    // -- helpers -----------------------------------------------------------

    private function assertSameIds(
        string $field,
        string $op,
        mixed $value,
    ): void {
        $viaIndex = array_column(
            $this->db->table('indexed')
                ->where($field, $op, $value)->selectAllByArray(),
            'id',
        );
        $viaTwin = array_column(
            $this->db->table('twin')
                ->where($field, $op, $value)->selectAllByArray(),
            'id',
        );

        sort($viaIndex);
        sort($viaTwin);

        Assert::same(
            $viaIndex,
            $viaTwin,
            "{$field} {$op} " . var_export($value, true),
        );
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
