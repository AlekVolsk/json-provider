<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\Locale\LocaleInterface;
use AV\JsonProvider\Index\IndexEntryList;
use AV\JsonProvider\Index\IndexFileRegion;
use AV\JsonProvider\Index\IndexKey;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Reading the sorted head of an index file: entries in the form the engine
 * writes are read without decoding JSON, a run of them in one pass; any
 * other form is decoded. Both readings accept and refuse exactly the same
 * entries. A lookup can be capped by an entry budget.
 */
final class IndexFileRegionTest
{
    private const int LINES = 1000;

    private string $root;

    private IndexSchema $index;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-index-file-region');
        mkdir($this->root, 0755, true);
        $this->index = new IndexSchema('idx_n', [
            new IndexFieldSchema('n', SortDirectionEnum::ASC),
        ]);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * Every entry text gives what decoding it as JSON gives — the same key
     * and line, or the same refusal.
     */
    #[Test]
    public function entryReadingMatchesDecoding(): void
    {
        $key = IndexKey::build(['n' => 7], $this->index);
        $texts = [
            '{"key":"' . $key . '","line":0}',
            '{"key":"' . $key . '","line":42}',
            '{"key":"' . $key . '","line":999}',
            '{"key":"' . $key . '","line":1000}',
            '{"key":"' . $key . '","line":007}',
            '{"key":"' . $key . '","line":-1}',
            '{"key":"' . $key . '","line":1234567890123456789012}',
            '{"key":"' . strtoupper($key) . '","line":3}',
            '{"key":"' . substr($key, 0, 4) . '\u0030' . substr($key, 5)
                . '","line":3}',
            '{"key":"' . $key . '","line":3,"x":1}',
            '{"line":3,"key":"' . $key . '"}',
            '{"key":"' . $key . '", "line":3}',
            '{"key":"","line":3}',
            '{"key":"' . $key . '","line":"3"}',
            '{"key":"' . $key . '","line":3.0}',
            'not json',
        ];

        foreach ($texts as $text) {
            Assert::same(
                $this->outcome(
                    fn (\Closure $fail): array => IndexFileRegion::parse(
                        $text . "\n",
                        $this->index,
                        self::LINES,
                        $fail,
                    ),
                ),
                $this->reference($text),
                $text,
            );
        }
    }

    /**
     * A run read in one pass gives the lines and keys line-by-line reading
     * gives; a run with one entry in another form is read line by line.
     */
    #[Test]
    public function runIsReadInOnePassOrLineByLine(): void
    {
        $entries = $this->entries(200);

        foreach ([false, true] as $odd) {
            $texts = $this->texts($entries);

            if ($odd) {
                $texts[57] = str_replace('"line":', '"line" :', $texts[57]);
            }

            $region = $this->region($texts);
            $lines = $region->lines(0, $region->end());

            Assert::same($lines, array_column($entries, 'line'));
            Assert::same(
                $region->keys(),
                array_combine(
                    array_column($entries, 'line'),
                    array_column($entries, 'key'),
                ),
            );
        }
    }

    /**
     * A run read in one pass is checked like any run: keys in order, line
     * references inside the data file.
     */
    #[Test]
    public function runInOnePassIsChecked(): void
    {
        $entries = $this->entries(50);
        $swapped = $entries;
        [$swapped[0], $swapped[49]] = [$swapped[49], $swapped[0]];
        $outside = $entries;
        $outside[20]['line'] = self::LINES;

        foreach (
            [
                [$swapped, 'IndexOrderBroken'],
                [$outside, 'IndexBrokenPermutation'],
            ] as [$broken, $reason]
        ) {
            $region = $this->region($this->texts($broken));

            Assert::same(
                $this->outcome(
                    static fn (): array => $region->lines(0, $region->end()),
                ),
                $reason,
            );
        }
    }

    /**
     * An IN lookup returns exactly the lines of its values — adjacent runs
     * read as one, gaps left out — whether the entries are in memory or the
     * sorted head of a file.
     */
    #[Test]
    public function inLookupReturnsOnlyItsRuns(): void
    {
        $entries = $this->entries(300);
        $manager = new IndexManager(new NdjsonStorage($this->root));
        $sets = [[3], [3, 4, 5], [0, 2, 4, 6], [36, 1, 18, 19, 20], [99], []];

        foreach ($sets as $values) {
            $condition = new FilterCondition(
                'n',
                FilterOperatorEnum::IN,
                $values,
            );
            $expected = [];

            foreach ($entries as $entry) {
                if (\in_array($entry['line'] % 37, $values, true)) {
                    $expected[] = $entry['line'];
                }
            }

            sort($expected);

            $lists = [
                new IndexEntryList($entries),
                $this->region($this->texts($entries)),
            ];

            foreach ($lists as $sorted) {
                $lines = $manager->searchLinesIn(
                    $sorted,
                    $this->index,
                    $condition,
                );
                Assert::true($lines !== null);
                sort($lines);
                Assert::same($lines, $expected, implode(',', $values));
            }
        }
    }

    /**
     * Under a budget, runs are read while the entries they are estimated
     * to hold fit it; past it nothing more is read and the lookup is over
     * budget.
     */
    #[Test]
    public function budgetStopsReadingRuns(): void
    {
        $texts = $this->texts($this->entries(100));
        $region = $this->region($texts, 100);
        $half = \strlen(implode("\n", \array_slice($texts, 0, 50))) + 1;
        $region->limit(60);

        Assert::same(\count($region->lines(0, $half)), 50);
        Assert::false($region->overBudget());
        Assert::same($region->lines($half, $region->end()), []);
        Assert::true($region->overBudget());
        Assert::same($region->lines(0, $half), []);
    }

    /**
     * @return array<int,array{key:string,line:int}> sorted by key
     */
    private function entries(int $count): array
    {
        $entries = [];

        for ($line = 0; $line < $count; $line++) {
            $entries[] = [
                'key'  => IndexKey::build(['n' => $line % 37], $this->index),
                'line' => $line,
            ];
        }

        usort(
            $entries,
            static function (array $a, array $b): int {
                $order = strcmp($a['key'], $b['key']);

                return $order !== 0 ? $order : $a['line'] <=> $b['line'];
            },
        );

        return $entries;
    }

    /**
     * @param array<int,array<string,int|string>> $entries
     *
     * @return array<int,string>
     */
    private function texts(array $entries): array
    {
        return array_map(
            static fn (array $e): string => json_encode(
                $e,
                JSON_THROW_ON_ERROR,
            ),
            $entries,
        );
    }

    /**
     * @param array<int,string> $texts
     */
    private function region(array $texts, int $count = 0): IndexFileRegion
    {
        $path = $this->root . '/' . uniqid('idx', true) . '.ndjson';
        $raw = implode("\n", $texts) . "\n";
        file_put_contents($path, $raw);

        return new IndexFileRegion(
            $path,
            \strlen($raw),
            $this->index,
            self::LINES,
            self::fail(...),
            $count,
        );
    }

    /**
     * @param \Closure(\Closure): mixed $read
     */
    private function outcome(\Closure $read): mixed
    {
        try {
            return $read(self::fail(...));
        } catch (\DomainException $e) {
            return $e->getMessage();
        }
    }

    /**
     * What decoding the entry as JSON gives: the key and line, or the name
     * of the refusal.
     */
    private function reference(string $text): mixed
    {
        $entry = json_decode($text, true);
        $key = \is_array($entry) ? ($entry['key'] ?? null) : null;
        $line = \is_array($entry) ? ($entry['line'] ?? null) : null;

        if (!\is_string($key) || !\is_int($line)) {
            return 'IndexEntryMalformed';
        }

        if ($line < 0 || $line >= self::LINES) {
            return 'IndexBrokenPermutation';
        }

        if (!IndexKey::wellFormed($key, $this->index)) {
            return 'IndexKeyMalformed';
        }

        return ['key' => $key, 'line' => $line];
    }

    private static function fail(
        LocaleInterface $reason,
        string ...$details,
    ): never {
        throw new \DomainException($reason->name);
    }
}
