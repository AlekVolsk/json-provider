<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Query\ComparisonMode;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
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
 * Property tests for the binary index search: for every operator the
 * lines returned by searchLines over a validated index must equal the
 * linear reference — filtering the records with the operator itself.
 * Boundary shapes: empty index, single entry, all keys equal, values
 * outside the range on both sides, duplicates, ASC and DESC directions.
 */
final class IndexBinarySearchTest
{
    private const string DB_PATH = '/tmp/jp-ixbinsearch-tests';

    private string $dbDir;

    private NdjsonStorage $storage;

    private IndexManager $manager;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
        mkdir($this->dbDir . '/t', 0755, true);
        $this->storage = new NdjsonStorage($this->dbDir);
        $this->manager = new IndexManager($this->storage);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    #[Test]
    public function intDatasetMatchesLinearReferenceAsc(): void
    {
        $values = [5, 1, 9, 5, 3, 7, 5, 1, 42, -10, 0, 9, 100, -3, 7];
        $probes = [-99, -10, 0, 1, 4, 5, 9, 42, 100, 999];

        $this->assertAllOperators($values, $probes, SortDirectionEnum::ASC);
    }

    #[Test]
    public function intDatasetMatchesLinearReferenceDesc(): void
    {
        $values = [5, 1, 9, 5, 3, 7, 5, 1, 42, -10, 0, 9, 100, -3, 7];
        $probes = [-99, -10, 0, 1, 4, 5, 9, 42, 100, 999];

        $this->assertAllOperators($values, $probes, SortDirectionEnum::DESC);
    }

    #[Test]
    public function stringDatasetMatchesLinearReference(): void
    {
        $values = ['b', 'a', 'ab', 'b', '10', '9', '100', '', 'a', 'zz'];
        $probes = ['', '0', '10', '5', '9', 'a', 'ab', 'b', 'zz', 'zzz'];

        $this->assertAllOperators($values, $probes, SortDirectionEnum::ASC);
        $this->assertAllOperators($values, $probes, SortDirectionEnum::DESC);
    }

    #[Test]
    public function floatDatasetMatchesLinearReference(): void
    {
        $values = [1.5, -0.5, 0.0, 2.5, 1.5, 99.0, -0.5, 3.25];
        $probes = [-1.0, -0.5, 0.0, 1.5, 2.0, 99.0, 100.0];

        $this->assertAllOperators($values, $probes, SortDirectionEnum::ASC);
        $this->assertAllOperators($values, $probes, SortDirectionEnum::DESC);
    }

    #[Test]
    public function emptyIndexReturnsEmptyForEveryOperator(): void
    {
        $this->assertAllOperators([], [0, 'x'], SortDirectionEnum::ASC);
    }

    #[Test]
    public function singleEntryIndexMatchesLinearReference(): void
    {
        $this->assertAllOperators([7], [6, 7, 8], SortDirectionEnum::ASC);
        $this->assertAllOperators([7], [6, 7, 8], SortDirectionEnum::DESC);
    }

    #[Test]
    public function allEqualKeysMatchLinearReference(): void
    {
        $values = [4, 4, 4, 4, 4];
        $probes = [3, 4, 5];

        $this->assertAllOperators($values, $probes, SortDirectionEnum::ASC);
        $this->assertAllOperators($values, $probes, SortDirectionEnum::DESC);
    }

    // -- helpers -----------------------------------------------------------

    /**
     * The declared column type matching the homogeneous dataset — the
     * schema boundary rejects unknown types, so the value column must be
     * typed per dataset.
     *
     * @param array<int,null|scalar> $values
     */
    private static function columnTypeFor(array $values): string
    {
        foreach ($values as $value) {
            if (\is_int($value)) {
                return 'int';
            }

            if (\is_float($value)) {
                return 'float';
            }

            if (\is_string($value)) {
                return 'string';
            }

            if (\is_bool($value)) {
                return 'bool';
            }
        }

        return 'int';
    }

    /**
     * Builds an index over the values, then checks EQ/GT/GTE/LT/LTE with
     * every probe, BETWEEN with every probe pair, and IN with probe
     * subsets — each against the linear reference (the operator applied
     * record by record).
     *
     * @param array<int,null|scalar> $values
     * @param array<int,null|scalar> $probes
     */
    private function assertAllOperators(
        array $values,
        array $probes,
        SortDirectionEnum $direction,
    ): void {
        $index = new IndexSchema(
            name: 'idx_v',
            fields: [new IndexFieldSchema('v', $direction)],
        );
        $tableSchema = TableSchema::create(
            name: 't',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'v' => self::columnTypeFor($values)],
            indexes: [$index],
        );

        $records = [];

        foreach ($values as $i => $value) {
            $records[] = ['id' => $i + 1, 'v' => $value];
        }

        touch($this->dbDir . '/t/' . $index->getFileName());
        $this->manager->rebuildOne('t', $index, $records);
        $entries = $this->manager->readIndexValidated(
            't',
            $index,
            \count($records),
            true,
        );
        Assert::notNull($entries);

        $scalarOps = [
            FilterOperatorEnum::EQ,
            FilterOperatorEnum::GT,
            FilterOperatorEnum::GTE,
            FilterOperatorEnum::LT,
            FilterOperatorEnum::LTE,
        ];

        foreach ($probes as $probe) {
            foreach ($scalarOps as $op) {
                $this->assertSearchMatchesLinear(
                    $tableSchema,
                    $entries,
                    $index,
                    $records,
                    new FilterCondition('v', $op, $probe),
                );
            }
        }

        foreach ($probes as $from) {
            foreach ($probes as $to) {
                $this->assertSearchMatchesLinear(
                    $tableSchema,
                    $entries,
                    $index,
                    $records,
                    new FilterCondition(
                        'v',
                        FilterOperatorEnum::BETWEEN,
                        [$from, $to],
                    ),
                );
            }
        }

        $inSets = [
            [],
            \array_slice($probes, 0, 1),
            \array_slice($probes, 0, 3),
            $probes,
        ];

        foreach ($inSets as $set) {
            $this->assertSearchMatchesLinear(
                $tableSchema,
                $entries,
                $index,
                $records,
                new FilterCondition('v', FilterOperatorEnum::IN, $set),
            );
        }
    }

    /**
     * The engine contract: the index may return a SUPERSET of the exact
     * matches (numeric range bounds select whole equal-double runs), it
     * must never lose a matching row, and applying the exact operator to
     * the returned rows must reproduce the linear reference — which is
     * precisely what the post-filter on every range path does.
     *
     * @param array<int,array{key:string,line:int}> $entries
     * @param array<int,array<string,null|scalar>>  $records
     */
    private function assertSearchMatchesLinear(
        TableSchema $tableSchema,
        array $entries,
        IndexSchema $index,
        array $records,
        FilterCondition $condition,
    ): void {
        $lines = $this->manager->searchLines(
            $tableSchema,
            $entries,
            $index,
            $condition,
        );

        if ($lines === null) {
            return;
        }

        $expected = [];

        foreach ($records as $line => $record) {
            if ($condition->matches($record, ComparisonMode::Binary)) {
                $expected[] = $line;
            }
        }

        $label = $condition->operator->name . ' '
            . var_export($condition->value, true)
            . ' dir=' . $index->fields[0]->direction->name;

        Assert::same(
            array_values(array_diff($expected, $lines)),
            [],
            "no matching row may be lost: {$label}",
        );

        $trimmed = [];

        foreach ($lines as $line) {
            if ($condition->matches($records[$line], ComparisonMode::Binary)) {
                $trimmed[] = $line;
            }
        }

        sort($trimmed);
        sort($expected);

        Assert::same($trimmed, $expected, "post-filtered set: {$label}");
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
