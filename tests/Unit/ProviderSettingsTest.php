<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\ComparisonModeEnum;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\FkBackingPolicyEnum;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\BrokenRecordPolicyEnum;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The settings of a provider instance — comparison mode, broken record
 * policy, FK backing policy — apply to the very next call after they are
 * changed, on an instance that has already served calls, and changing
 * them back applies just as well.
 */
final class ProviderSettingsTest
{
    private string $root;

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-provider-settings');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
        $this->db->createTable(TableSchema::create(
            name: 'owners',
            columns: ['name' => ColumnTypes::STRING],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'items',
            columns: [
                'ownerId' => ColumnTypes::INT,
                'title'   => ColumnTypes::STRING,
            ],
            indexes: [new IndexSchema('idx_owner_title', [
                new IndexFieldSchema('ownerId', SortDirectionEnum::ASC),
                new IndexFieldSchema('title', SortDirectionEnum::ASC),
            ])],
        ));
        $this->db->insert('owners', ['name' => 'o']);

        foreach (['a', 'B', 'c'] as $title) {
            $this->db->insert('items', ['ownerId' => 1, 'title' => $title]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * Binary orders "B" before "a" by bytes, Locale after it by the
     * collator; each select follows the mode set last.
     */
    #[Test]
    public function comparisonModeAppliesToNextCall(): void
    {
        $modes = [
            [ComparisonModeEnum::Binary, ['B', 'a', 'c']],
            [ComparisonModeEnum::Locale, ['a', 'B', 'c']],
            [ComparisonModeEnum::Binary, ['B', 'a', 'c']],
        ];

        foreach ($modes as [$mode, $order]) {
            $this->db->setComparisonMode($mode);

            Assert::same(
                array_column(
                    $this->db->table('items')
                        ->orderBy('title', 'ASC')
                        ->selectAllByArray(),
                    'title',
                ),
                $order,
                $mode->name,
            );
        }
    }

    /**
     * A rewrite over a line that is not a record is refused under Refuse
     * and drops the line under Drop, as the policy set last says.
     */
    #[Test]
    public function brokenRecordPolicyAppliesToNextCall(): void
    {
        $path = $this->dbDir . '/items/items.ndjson';
        $lines = explode("\n", (string)file_get_contents($path));
        $lines[1] = '#' . substr($lines[1], 1);
        file_put_contents($path, implode("\n", $lines));
        $update = fn () => $this->db->table('items')->where('id', '=', 3)
            ->updateByArray(['title' => 'C']);

        $this->db->setBrokenRecordPolicy(BrokenRecordPolicyEnum::Refuse);

        try {
            $update();
            Assert::fail('the rewrite was not refused');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'BrokenRecordBlocksRewrite');
        }

        $this->db->setBrokenRecordPolicy(BrokenRecordPolicyEnum::Drop);
        $update();

        Assert::same(
            array_column($this->db->readAll('items'), 'title'),
            ['a', 'C'],
        );
    }

    /**
     * addRelation backs a probing relation with a service index under
     * SingleColumn and with the composite index led by the FK column under
     * LeadingColumn.
     */
    #[Test]
    public function fkBackingPolicyAppliesToNextCall(): void
    {
        $policies = [
            [FkBackingPolicyEnum::SingleColumn, '_fk_'],
            [FkBackingPolicyEnum::LeadingColumn, 'idx_owner_title'],
            [FkBackingPolicyEnum::SingleColumn, '_fk_'],
        ];

        foreach ($policies as [$policy, $backing]) {
            $this->db->setFkBackingPolicy($policy);
            $this->db->addRelation(new RelationSchema(
                fromTable: 'items',
                foreignKey: 'ownerId',
                toTable: 'owners',
                references: 'id',
                type: RelationTypeEnum::BELONGS_TO,
                onDelete: ForeignKeyActionEnum::RESTRICT,
            ));
            $chosen = $this->db->relations('items')[0]->backingIndex;

            Assert::true(
                \is_string($chosen) && str_starts_with($chosen, $backing),
                $policy->name . ': ' . var_export($chosen, true),
            );

            $this->db->dropRelation('items', 'ownerId', 'owners');
        }
    }
}
