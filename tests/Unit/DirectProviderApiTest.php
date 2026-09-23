<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\JsonFilter;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The stateless data-access methods of JsonDataProvider: every call gets
 * its conditions, ordering and pagination explicitly, update/delete return
 * the number of affected rows, an id in an update patch is dropped, and an
 * empty condition list matches every row.
 */
final class DirectProviderApiTest
{
    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = JsonDataProvider::createDatabase(
            self::root() . '/' . uniqid('db', true),
        );
        $this->db->createTable(TableSchema::create(
            name: 'products',
            columns: [
                'name'   => 'string',
                'price'  => 'float',
                'active' => 'bool',
            ],
        ));

        $rows = [
            ['Widget', 9.99, true],
            ['Gadget', 19.5, true],
            ['Gizmo', 4.0, false],
        ];

        foreach ($rows as [$name, $price, $active]) {
            $this->db->insert('products', [
                'name'   => $name,
                'price'  => $price,
                'active' => $active,
            ]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove(self::root());
    }

    #[Test]
    public function readsTakeConditionsOrderingAndPaginationExplicitly(): void
    {
        $active = (new JsonFilter())
            ->where('active', '=', true)
            ->getConditions();

        $rows = $this->db->select(
            'products',
            $active,
            [OrderBy::desc('price')],
            limit: 10,
            offset: 0,
        );

        Assert::same(array_column($rows, 'name'), ['Gadget', 'Widget']);
        Assert::same($this->db->count('products', $active), 2);
        Assert::same($this->db->count('products'), 3);
        Assert::same(\count($this->db->readAll('products')), 3);
        Assert::same(
            array_column(
                $this->db->select('products', distinctFields: ['active']),
                'active',
            ),
            [true, false],
        );
    }

    #[Test]
    public function writesReturnAffectedRowCounts(): void
    {
        $cheap = [new FilterCondition('price', FilterOperatorEnum::LT, 10.0)];

        Assert::same(
            $this->db->update(
                'products',
                $cheap,
                ['active' => false, 'id' => 99],
            ),
            2,
        );
        Assert::same(
            array_column($this->db->readAll('products'), 'id'),
            [1, 2, 3],
        );
        Assert::same(
            $this->db->update(
                'products',
                [new FilterCondition('name', FilterOperatorEnum::EQ, 'Nope')],
                ['active' => true],
            ),
            0,
        );
        Assert::same(
            $this->db->delete(
                'products',
                [new FilterCondition('active', FilterOperatorEnum::EQ, false)],
            ),
            2,
        );
        Assert::same($this->db->delete('products', []), 1);
        Assert::same($this->db->count('products'), 0);
    }

    #[Test]
    public function invalidArgumentsRaiseDomainErrors(): void
    {
        $cases = [
            'InvalidLimit' => fn () => $this->db->select(
                'products',
                limit: -1,
            ),
            'InvalidOffset' => fn () => $this->db->select(
                'products',
                offset: -1,
            ),
            'QueryUnknownColumn' => fn () => $this->db->count(
                'products',
                [new FilterCondition('nope', FilterOperatorEnum::EQ, 1)],
            ),
        ];

        foreach ($cases as $key => $call) {
            try {
                $call();
                Assert::fail('expected ' . $key);
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), $key);
            }
        }
    }

    private static function root(): string
    {
        return TempDir::root('jp-direct-api-tests');
    }
}
