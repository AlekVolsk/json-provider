<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Query edges where the index path and the full scan must agree, and the
 * documented condition semantics that differ from SQL:
 *  - limit(0) returns nothing on every path; limit(PHP_INT_MAX) combined
 *    with an offset does not overflow;
 *  - conditions are two-valued: not: true returns every row the condition
 *    did not match, null rows included;
 *  - distinct keeps -0.0 and 0.0 apart while = treats them as equal.
 */
final class QueryEdgeTest
{
    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = JsonDataProvider::createDatabase(
            self::root() . '/' . uniqid('db', true),
        );
        $this->db->createTable(TableSchema::create(
            name: 'q',
            columns: [
                'price' => 'int|null',
                'name'  => 'string',
                'g'     => 'float',
            ],
            indexes: [new IndexSchema('by_price', [
                new IndexFieldSchema('price', SortDirectionEnum::ASC),
            ])],
        ));

        foreach ([[30, 'c', 0.0], [10, 'a', -0.0], [null, 'b', 1.0]] as $row) {
            $this->db->insert('q', [
                'price' => $row[0],
                'name'  => $row[1],
                'g'     => $row[2],
            ]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove(self::root());
    }

    #[Test]
    public function limitZeroReturnsNothingOnEveryPath(): void
    {
        $table = $this->db->table('q');

        Assert::same(
            $table->where('price', '>', 0)->orderBy('price')->limit(0)
                ->selectAllByArray(),
            [],
        );
        Assert::same(
            $table->where('price', '>', 0)->orderBy('id')->limit(0)
                ->selectAllByArray(),
            [],
        );
        Assert::same(
            $table->where('price', '=', 10)->limit(0)->selectAllByArray(),
            [],
        );
        Assert::same(
            $table->orderBy('name')->limit(0)->selectAllByArray(),
            [],
        );
        Assert::null(
            $table->where('price', '>', 0)->orderBy('price')->limit(0)
                ->selectOneByArray(),
        );
    }

    #[Test]
    public function maximalLimitWithOffsetDoesNotOverflow(): void
    {
        $table = $this->db->table('q');

        Assert::same(
            $table->orderBy('name')->limit(PHP_INT_MAX)->offset(1)
                ->selectColumn('name'),
            ['b', 'c'],
        );
        Assert::same(
            $table->orderBy('name')->limit(PHP_INT_MAX - 1)->offset(2)
                ->selectColumn('name'),
            ['c'],
        );
        Assert::same(
            $table->where('price', '>', 0)->orderBy('price')
                ->limit(PHP_INT_MAX)->offset(1)->selectColumn('price'),
            [30],
        );
        Assert::same(
            $table->orderBy('name')->limit(5)->offset(PHP_INT_MAX)
                ->selectAllByArray(),
            [],
        );
    }

    #[Test]
    public function negationIsTwoValuedAndIncludesNullRows(): void
    {
        Assert::same($this->names(['price', '>', 20, false]), ['c']);
        Assert::same($this->names(['price', '>', 20, true]), ['a', 'b']);
        Assert::same($this->names(['price', '<', 20, false]), ['a']);
        Assert::same($this->names(['price', '=', null, false]), ['b']);
        Assert::same($this->names(['price', '=', null, true]), ['a', 'c']);
        Assert::same(
            $this->names(['price', '>', 20, true], ['price', '=', null, true]),
            ['a'],
        );
    }

    #[Test]
    public function distinctKeepsNegativeZeroApartWhileEqualsDoesNot(): void
    {
        Assert::same(
            $this->db->table('q')->where('g', '=', 0.0)->count(),
            2,
        );
        Assert::same(
            $this->db->table('q')->distinct('g')->count(),
            3,
        );
    }

    /**
     * Names of the rows matching all given conditions, each written as
     * [field, operator, value, not].
     *
     * @param array{string, string, mixed, bool} ...$conditions
     *
     * @return array<int,null|scalar>
     */
    private function names(array ...$conditions): array
    {
        $table = $this->db->table('q');

        foreach ($conditions as [$field, $operator, $value, $not]) {
            $table->where($field, $operator, $value, $not);
        }

        return $table->orderBy('name')->selectColumn('name');
    }

    private static function root(): string
    {
        return TempDir::root('jp-query-edge-tests');
    }
}
