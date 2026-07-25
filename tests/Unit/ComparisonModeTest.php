<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\ComparisonMode;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Query\ValueComparator;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the unified ordering comparator and the comparison mode:
 *
 *  - Binary (default): strings compare bytewise everywhere — the range
 *    operators, ORDER BY and the byte-ordered index agree on one answer
 *    ('10' < '9', no numeric-string coercion);
 *  - numbers keep numeric comparison; null sorts first; equality stays
 *    strict in both modes;
 *  - Locale: string ORDER BY/ranges go through the intl Collator when
 *    ext-intl is present and silently fall back to Binary otherwise;
 *    indexes are excluded from string ordering/ranges in this mode, and
 *    results stay consistent with the full-scan comparator.
 */
final class ComparisonModeTest
{
    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: 'strs',
            uniqueConstraints: [],
            columns: ['id' => 'int', 's' => 'string', 'n' => 'int'],
            indexes: [
                new IndexSchema(
                    name: 'idx_s',
                    fields: [new IndexFieldSchema(
                        's',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));

        foreach ([['9', 9], ['10', 10], ['100', 100]] as [$s, $n]) {
            $this->db->insert('strs', ['s' => $s, 'n' => $n]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->setComparisonMode(ComparisonMode::Binary);
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function nullSortsFirst(): void
    {
        Assert::true(ValueComparator::compare(null, 'a') < 0);
        Assert::true(ValueComparator::compare('a', null) > 0);
        Assert::same(ValueComparator::compare(null, null), 0);
    }

    #[Test]
    public function numbersCompareNumerically(): void
    {
        Assert::true(ValueComparator::compare(9, 10) < 0);
        Assert::true(ValueComparator::compare(9.5, 10) < 0);
        Assert::same(ValueComparator::compare(2, 2.0), 0);
    }

    #[Test]
    public function stringsCompareBytewiseInBinary(): void
    {
        Assert::true(ValueComparator::compare('10', '9') < 0);
        Assert::true(ValueComparator::compare('100', '10') > 0);
        Assert::true(ValueComparator::compare('B', 'a') < 0);
    }

    #[Test]
    public function localeModeUsesCollatorOrFallsBack(): void
    {
        $result = ValueComparator::compare('B', 'a', ComparisonMode::Locale);

        if (\extension_loaded('intl')) {
            $collator = new \Collator(\Locale::getDefault());
            $expected = $collator->compare('B', 'a');
            \assert(\is_int($expected));
            Assert::same($result <=> 0, $expected <=> 0);
        } else {
            Assert::same($result <=> 0, strcmp('B', 'a') <=> 0);
        }
    }

    #[Test]
    public function stringRangeConsistentAcrossAllPaths(): void
    {
        $viaIndex = array_column(
            $this->db->table('strs')
                ->where('s', '>', '10')->selectAllByArray(),
            's',
        );
        sort($viaIndex, SORT_STRING);

        $ordered = array_column(
            $this->db->table('strs')->orderBy('s')->selectAllByArray(),
            's',
        );
        $viaOrder = array_values(array_filter(
            $ordered,
            static fn (mixed $s): bool => \is_string($s)
                && strcmp($s, '10') > 0,
        ));
        sort($viaOrder, SORT_STRING);

        Assert::same($viaIndex, $viaOrder);
        Assert::same($viaIndex, ['100', '9']);
    }

    #[Test]
    public function byteOrderByDoesNotCoerceNumericStrings(): void
    {
        $ordered = array_column(
            $this->db->table('strs')->orderBy('s')->selectAllByArray(),
            's',
        );

        Assert::same($ordered, ['10', '100', '9']);
    }

    #[Test]
    public function stringBetweenIsBytewise(): void
    {
        $rows = $this->db->table('strs')
            ->where('s', 'BETWEEN', ['10', '9'])->selectAllByArray();

        Assert::count($rows, 3);
    }

    #[Test]
    public function numericRangesUnchanged(): void
    {
        $rows = $this->db->table('strs')
            ->where('n', '>', 9)->selectAllByArray();

        Assert::count($rows, 2);
        Assert::same(array_column($rows, 'n'), [10, 100]);
    }

    #[Test]
    public function localeModeStaysConsistentWithFullScan(): void
    {
        $this->db->setComparisonMode(ComparisonMode::Locale);

        $viaQuery = array_column(
            $this->db->table('strs')
                ->where('s', '>', '10')->selectAllByArray(),
            's',
        );
        sort($viaQuery, SORT_STRING);

        $all = array_column(
            $this->db->table('strs')->selectAllByArray(),
            's',
        );
        $expected = array_values(array_filter(
            $all,
            static fn (mixed $s): bool => \is_string($s)
                && ValueComparator::compare(
                    $s,
                    '10',
                    ComparisonMode::Locale,
                ) > 0,
        ));
        sort($expected, SORT_STRING);

        Assert::same($viaQuery, $expected);
    }

    #[Test]
    public function localeModeKeepsEqualityExactAndIndexed(): void
    {
        $this->db->setComparisonMode(ComparisonMode::Locale);

        $rows = $this->db->table('strs')
            ->where('s', '=', '10')->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['s'], '10');

        $rows = $this->db->table('strs')
            ->where('s', 'IN', ['9', '100'])->selectAllByArray();

        Assert::count($rows, 2);
    }

    #[Test]
    public function localeOrderingViaConditionPickedIndexUsesComparator(): void
    {
        $this->db->setComparisonMode(ComparisonMode::Locale);

        $rows = $this->db->table('strs')
            ->where('s', 'IN', ['9', '10', '100'])
            ->orderBy('s')
            ->selectAllByArray();

        $expected = array_column($rows, 's');
        usort(
            $expected,
            static fn (
                bool | float | int | string | null $a,
                bool | float | int | string | null $b,
            ): int => ValueComparator::compare(
                $a,
                $b,
                ComparisonMode::Locale,
            ),
        );

        Assert::same(array_column($rows, 's'), $expected);
    }

    #[Test]
    public function localeModeOrderByAgreesWithComparator(): void
    {
        $this->db->setComparisonMode(ComparisonMode::Locale);

        $ordered = array_column(
            $this->db->table('strs')->orderBy('s')->selectAllByArray(),
            's',
        );

        $expected = ['10', '100', '9'];
        usort(
            $expected,
            static fn (string $a, string $b): int => ValueComparator::compare(
                $a,
                $b,
                ComparisonMode::Locale,
            ),
        );

        Assert::same($ordered, $expected);
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
        return TempDir::root('jp-cmpmode-tests');
    }
}
