<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Services\Integrity\IssueCategoryEnum;
use AV\JsonProvider\Services\Integrity\IssueSeverityEnum;
use AV\JsonProvider\Storage\StorageManifest;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Unique constraints checked on insert through an index on the same fields.
 *
 * The check reads only the records whose index key equals the incoming
 * record's and compares them by the constraint's type-strict key. Without
 * such an index, or when the index cannot be searched in place, it reads
 * the whole table. Damage to the index off the path of the lookup is left
 * to validate(), as it is for queries.
 */
final class UniqueIndexCheckTest
{
    private const string TABLE = 'codes';

    private const string TWIN = 'codes_twin';

    private string $root;

    private string $dbDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-unique-index-check');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * Random inserts with frequent conflicts, nulls, -0.0 and ints into a
     * float column are accepted and refused exactly as by the full check
     * of an unindexed twin — over a composite constraint whose index lists
     * the fields in another order and direction, with tails merged on the
     * way.
     */
    #[Test]
    public function insertsMatchFullCheckOfTwin(): void
    {
        $db = $this->database();
        (new \ReflectionProperty(JsonDataProvider::class, 'indexTailLimit'))
            ->setValue($db, 16);
        mt_srand(20261005);
        $refused = 0;

        for ($i = 0; $i < 400; $i++) {
            $record = [
                'code' => mt_rand(0, 9) === 0 ? null : 'k' . mt_rand(0, 199),
                'a'    => mt_rand(0, 9) === 0 ? null : mt_rand(0, 29),
                'b'    => [null, 0.0, -0.0, 1.5, 2, 2.0, 3.25][mt_rand(0, 6)],
            ];
            $outcome = $this->outcome($db, self::TABLE, $record);
            $refused += $outcome === 'ok' ? 0 : 1;

            Assert::same(
                $outcome,
                $this->outcome($db, self::TWIN, $record),
                'insert #' . $i . ': ' . json_encode($record),
            );
        }

        Assert::true($refused > 100 && $refused < 300, (string)$refused);
        Assert::same(
            $db->table(self::TABLE)->selectAllByArray(),
            $db->table(self::TWIN)->selectAllByArray(),
        );
    }

    /**
     * A record whose stored key no longer matches the index entry that
     * points at it is found on the path of the lookup: the insert is
     * refused as INDEX_UNRELIABLE instead of judged on a wrong record.
     */
    #[Test]
    public function damageOnTheLookupPathRaises(): void
    {
        $db = $this->seeded();
        $this->editDataLine(150, '"c0151"', '"c9999"');

        try {
            $db->insert(self::TABLE, ['code' => 'c0151']);
            Assert::fail('a damaged index served a unique check silently');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'IndexRecordMismatch');
        }

        Assert::same($db->table(self::TABLE)->count(), 300);
    }

    /**
     * The lookup does not read the table: a stored value the index does
     * not know about is not seen — through the single-field index and
     * through the pair's index with the fields reordered — and the
     * duplicates it lets through are reported by validate(), together
     * with the damaged index.
     */
    #[Test]
    public function damageOffTheLookupPathIsLeftToValidate(): void
    {
        $db = $this->seeded();
        $this->editDataLine(150, '"c0151"', '"c9999"');
        $this->editDataLine(150, '"a":151', '"a":999');

        $db->insert(self::TABLE, ['code' => 'c9999']);
        $db->insert(self::TABLE, ['a' => 999, 'b' => 0.5]);

        $report = $db->validate();
        Assert::same(
            \count($report->issuesByCategory(
                IssueCategoryEnum::UNIQUE_DUPLICATE,
            )),
            2,
        );
        Assert::true(
            $report->issuesByCategory(IssueCategoryEnum::INDEX_DRIFT) !== []
            || $report->issuesByCategory(
                IssueCategoryEnum::INDEX_FILE_CORRUPT,
            ) !== [],
        );
    }

    /**
     * When the sorted head of the index file is not recorded, the check
     * reads the whole table and sees every stored value.
     */
    #[Test]
    public function withoutSortedHeadTheWholeTableIsRead(): void
    {
        $db = $this->seeded();
        $this->editDataLine(150, '"c0151"', '"c9999"');
        unlink(
            $this->dbDir . '/' . StorageManifest::DIR . '/table.'
                . self::TABLE . '.indexes.json',
        );

        try {
            $db->insert(self::TABLE, ['code' => 'c9999']);
            Assert::fail('a duplicate passed the full check');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'UniqueViolation');
        }
    }

    /**
     * An index serves a constraint when it holds exactly the constraint's
     * fields, in any order and direction; a service index, a subset and a
     * superset do not.
     */
    #[Test]
    public function indexServesConstraintOnSameFields(): void
    {
        $constraint = new UniqueConstraint('uq_ab', ['a', 'b']);
        $ba = self::index('idx_ba', [
            'b' => SortDirectionEnum::DESC,
            'a' => SortDirectionEnum::ASC,
        ]);
        $service = new IndexSchema(
            '_fk_ab',
            [
                new IndexFieldSchema('a', SortDirectionEnum::ASC),
                new IndexFieldSchema('b', SortDirectionEnum::ASC),
            ],
            isService: true,
        );
        $subset = self::index('idx_a', ['a' => SortDirectionEnum::ASC]);
        $superset = self::index('idx_abc', [
            'a'    => SortDirectionEnum::ASC,
            'b'    => SortDirectionEnum::ASC,
            'code' => SortDirectionEnum::ASC,
        ]);

        Assert::same($constraint->indexIn([$subset, $ba]), $ba);
        Assert::same(
            $constraint->indexIn([$service, $subset, $superset]),
            null,
        );
    }

    /**
     * validate() points at a constraint every insert checks by reading the
     * whole table, and repair leaves the finding as it is.
     */
    #[Test]
    public function validateHintsAtMissingIndex(): void
    {
        $db = $this->database();
        $db->createTable(TableSchema::create(
            name: 'plain',
            columns: ['id' => ColumnTypes::INT, 'code' => ColumnTypes::STRING],
            uniqueConstraints: [new UniqueConstraint('uq_plain', ['code'])],
        ));

        $found = $db->validate()->issuesByCategory(
            IssueCategoryEnum::UNIQUE_INDEX_MISSING,
        );
        $tables = array_map(
            static fn ($issue): string | null => $issue->tableName,
            $found,
        );
        sort($tables);

        Assert::same($tables, ['codes_twin', 'codes_twin', 'plain']);
        Assert::same($found[0]->severity, IssueSeverityEnum::INFO);

        $repaired = $db->repair()->issuesByCategory(
            IssueCategoryEnum::UNIQUE_INDEX_MISSING,
        );
        Assert::same(\count($repaired), 3);
        Assert::same(
            array_filter($repaired, static fn ($i): bool => $i->repaired),
            [],
        );
    }

    /**
     * Two tables with the same columns and constraints — a code, and a
     * pair of an int and a float — one with indexes on the constrained
     * fields (the pair's in reverse order, one field descending), one
     * without.
     */
    private function database(): JsonDataProvider
    {
        $db = JsonDataProvider::createDatabase($this->dbDir);
        $columns = [
            'id'   => ColumnTypes::INT,
            'code' => ColumnTypes::STRING_NULLABLE,
            'a'    => ColumnTypes::INT_NULLABLE,
            'b'    => ColumnTypes::FLOAT_NULLABLE,
        ];
        $constraints = [
            new UniqueConstraint('uq_code', ['code']),
            new UniqueConstraint('uq_ab', ['a', 'b']),
        ];
        $db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: $columns,
            indexes: [
                self::index('idx_code', ['code' => SortDirectionEnum::ASC]),
                self::index('idx_ba', [
                    'b' => SortDirectionEnum::DESC,
                    'a' => SortDirectionEnum::ASC,
                ]),
            ],
            uniqueConstraints: $constraints,
        ));
        $db->createTable(TableSchema::create(
            name: self::TWIN,
            columns: $columns,
            uniqueConstraints: $constraints,
        ));

        return $db;
    }

    /**
     * The indexed table with 300 rows coded c0001..c0300, fully sorted.
     */
    private function seeded(): JsonDataProvider
    {
        $db = $this->database();
        $rows = [];

        for ($id = 1; $id <= 300; $id++) {
            $rows[] = [
                'id'   => $id,
                'code' => \sprintf('c%04d', $id),
                'a'    => $id,
                'b'    => 0.5,
            ];
        }

        $db->importRecords(self::TABLE, $rows);

        return $db;
    }

    /**
     * @param array<string,null|scalar> $record
     */
    private function outcome(
        JsonDataProvider $db,
        string $table,
        array $record,
    ): string {
        try {
            $db->insert($table, $record);

            return 'ok';
        } catch (JsonProviderException $e) {
            return $e->getErrorKey() . ': '
                . str_replace(self::TWIN, self::TABLE, $e->getMessage());
        }
    }

    /**
     * Replaces a value on one data line in place, keeping the file length
     * and inode, so the table stays trusted.
     */
    private function editDataLine(int $line, string $from, string $to): void
    {
        $path = $this->dbDir . '/' . self::TABLE . '/'
            . TableSchema::dataFileName(self::TABLE);
        $lines = explode("\n", rtrim((string)file_get_contents($path)));
        Assert::true(str_contains($lines[$line], $from));
        $lines[$line] = str_replace($from, $to, $lines[$line]);
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /**
     * @param array<string,SortDirectionEnum> $fields
     */
    private static function index(string $name, array $fields): IndexSchema
    {
        $parts = [];

        foreach ($fields as $field => $direction) {
            $parts[] = new IndexFieldSchema($field, $direction);
        }

        return new IndexSchema($name, $parts);
    }
}
