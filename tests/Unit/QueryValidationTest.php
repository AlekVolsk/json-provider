<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\FilterOperatorEnum;
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
 * Tests for query-layer validation: condition typing mirrors the write
 * contract (CONDITION_TYPE_MISMATCH, the single coercion is int into a
 * float column), BETWEEN/IN structure is enforced (CONDITION_MALFORMED),
 * unknown columns are rejected everywhere they can be referenced
 * (QUERY_UNKNOWN_COLUMN — closing the "typo deletes the table" hole),
 * pagination inputs are validated fail-fast (INVALID_LIMIT /
 * INVALID_OFFSET) and orderBy directions are strict but case-insensitive
 * (INVALID_SORT_DIRECTION).
 */
final class QueryValidationTest
{
    private const string TABLE = 'goods';

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
                'id'      => 'int',
                'name'    => 'string',
                'n'       => 'int',
                'price'   => 'float',
                'b'       => 'bool',
                'flag'    => 'int|null',
                'v'       => 'string|null',
                'created' => 'datetime',
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

        foreach (
            [
                ['a', 1, 99.0, true, null, '1'],
                ['b', 2, 100.5, false, 7, 'x'],
                ['c', 3, 0.5, true, null, null],
            ] as [$name, $n, $price, $b, $flag, $v]
        ) {
            $this->db->insert(self::TABLE, [
                'name'    => $name,
                'n'       => $n,
                'price'   => $price,
                'b'       => $b,
                'flag'    => $flag,
                'v'       => $v,
                'created' => '2026-01-0' . $n . ' 10:00:00',
            ]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function numericStringOnIntColumnRejected(): void
    {
        $this->expectKey('CONDITION_TYPE_MISMATCH', function (): void {
            $this->db->table(self::TABLE)
                ->where('n', '=', '2')->selectAllByArray();
        });
    }

    #[Test]
    public function numericStringRangeOnIntColumnRejected(): void
    {
        $this->expectKey('CONDITION_TYPE_MISMATCH', function (): void {
            $this->db->table(self::TABLE)
                ->where('n', '>', '0')->selectAllByArray();
        });
    }

    #[Test]
    public function intConditionFindsFloatColumn(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('price', '=', 99)->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['name'], 'a');
    }

    #[Test]
    public function intRangeBoundCoercesOnFloatColumn(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('price', '>', 99)->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['name'], 'b');
    }

    #[Test]
    public function inListCoercesElementwiseOnFloatColumn(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('price', 'IN', [99, 100.5])->selectAllByArray();

        Assert::count($rows, 2);
    }

    #[Test]
    public function numericStringOnFloatColumnRejected(): void
    {
        $this->expectKey('CONDITION_TYPE_MISMATCH', function (): void {
            $this->db->table(self::TABLE)
                ->where('price', '=', '9.5')->selectAllByArray();
        });
    }

    #[Test]
    public function intOnBoolColumnRejected(): void
    {
        $this->expectKey('CONDITION_TYPE_MISMATCH', function (): void {
            $this->db->table(self::TABLE)
                ->where('b', '=', 1)->selectAllByArray();
        });
    }

    #[Test]
    public function boolEqualityWorks(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('b', '=', true)->selectAllByArray();

        Assert::count($rows, 2);
    }

    #[Test]
    public function nullEqOnNullableColumnFindsNulls(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('flag', '=', null)->selectAllByArray();

        Assert::count($rows, 2);
    }

    #[Test]
    public function nullEqOnNonNullableColumnIsZeroRowsNotError(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('n', '=', null)->selectAllByArray();

        Assert::count($rows, 0);
    }

    #[Test]
    public function nullRangeBoundRejected(): void
    {
        $this->expectKey('CONDITION_TYPE_MISMATCH', function (): void {
            $this->db->table(self::TABLE)
                ->where('n', '>', null)->selectAllByArray();
        });
    }

    #[Test]
    public function nonFiniteFloatConditionRejected(): void
    {
        $this->expectKey('NON_FINITE_FLOAT', function (): void {
            $this->db->table(self::TABLE)
                ->where('price', '=', NAN)->selectAllByArray();
        });
        $this->expectKey('NON_FINITE_FLOAT', function (): void {
            $this->db->table(self::TABLE)
                ->where('price', '<', INF)->selectAllByArray();
        });
    }

    #[Test]
    public function temporalConditionRequiresString(): void
    {
        $this->expectKey('CONDITION_TYPE_MISMATCH', function (): void {
            $this->db->table(self::TABLE)
                ->where('created', '=', 12345)->selectAllByArray();
        });
    }

    #[Test]
    public function temporalStringConditionWorks(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('created', '>', '2026-01-01 23:00:00')
            ->selectAllByArray();

        Assert::count($rows, 2);
    }

    #[Test]
    public function invalidTemporalStringStillRejected(): void
    {
        $this->expectKey('INVALID_TEMPORAL_VALUE', function (): void {
            $this->db->table(self::TABLE)
                ->where('created', '=', 'not-a-date')->selectAllByArray();
        });
    }

    #[Test]
    public function likeOnNonStringColumnRejected(): void
    {
        $this->expectKey('CONDITION_TYPE_MISMATCH', function (): void {
            $this->db->table(self::TABLE)
                ->where('n', 'LIKE', '%1%')->selectAllByArray();
        });
    }

    #[Test]
    public function updateWithInvalidConditionFailsBeforeAnyWrite(): void
    {
        $before = md5((string)file_get_contents($this->dataPath()));

        $this->expectKey('CONDITION_TYPE_MISMATCH', function (): void {
            $this->db->table(self::TABLE)
                ->where('n', '=', '1')
                ->updateByArray(['name' => 'boom']);
        });

        Assert::same(
            md5((string)file_get_contents($this->dataPath())),
            $before,
        );
    }

    #[Test]
    public function deleteWithInvalidConditionFailsBeforeAnyWrite(): void
    {
        $before = md5((string)file_get_contents($this->dataPath()));

        $this->expectKey('CONDITION_TYPE_MISMATCH', function (): void {
            $this->db->table(self::TABLE)
                ->where('n', '=', '1')
                ->delete();
        });

        Assert::same(
            md5((string)file_get_contents($this->dataPath())),
            $before,
        );
    }

    #[Test]
    public function malformedBetweenRejected(): void
    {
        foreach ([[1], [], [1, 2, 3], 'str', [null, 2], [1, null]] as $bad) {
            $this->expectKey(
                'CONDITION_MALFORMED',
                function () use ($bad): void {
                    $this->db->table(self::TABLE)
                        ->where('n', 'BETWEEN', $bad)->selectAllByArray();
                },
            );
        }
    }

    #[Test]
    public function malformedInRejected(): void
    {
        $this->expectKey('CONDITION_MALFORMED', function (): void {
            $this->db->table(self::TABLE)
                ->where('n', 'IN', 5)->selectAllByArray();
        });
    }

    #[Test]
    public function emptyInIsAuthoritativeZero(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('n', 'IN', [])->selectAllByArray();

        Assert::count($rows, 0);
    }

    #[Test]
    public function associativeBetweenIsNormalized(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('n', 'BETWEEN', ['from' => 1, 'to' => 2])
            ->selectAllByArray();

        Assert::count($rows, 2);
    }

    #[Test]
    public function validBetweenAndInWork(): void
    {
        Assert::count(
            $this->db->table(self::TABLE)
                ->where('n', 'BETWEEN', [1, 10])->selectAllByArray(),
            3,
        );
        Assert::count(
            $this->db->table(self::TABLE)
                ->where('n', 'IN', [1, 2])->selectAllByArray(),
            2,
        );
    }

    #[Test]
    public function directBetweenMatchesWithShortArrayIsQuietFalse(): void
    {
        Assert::true(
            !FilterOperatorEnum::BETWEEN->matches(5, [1]),
        );
    }

    #[Test]
    public function unknownColumnInWhereRejectedOnEveryVerb(): void
    {
        $this->expectKey('QUERY_UNKNOWN_COLUMN', function (): void {
            $this->db->table(self::TABLE)
                ->where('typo', '=', 1)->selectAllByArray();
        });
        $this->expectKey('QUERY_UNKNOWN_COLUMN', function (): void {
            $this->db->table(self::TABLE)
                ->where('typo', '=', 1)->count();
        });
        $this->expectKey('QUERY_UNKNOWN_COLUMN', function (): void {
            $this->db->table(self::TABLE)
                ->where('typo', '=', 1)->updateByArray(['name' => 'x']);
        });
        $this->expectKey('QUERY_UNKNOWN_COLUMN', function (): void {
            $this->db->table(self::TABLE)
                ->where('typo', '=', 1)->delete();
        });
    }

    #[Test]
    public function negatedTypoDeleteDoesNotWipeTheTable(): void
    {
        $before = md5((string)file_get_contents($this->dataPath()));

        $this->expectKey('QUERY_UNKNOWN_COLUMN', function (): void {
            $this->db->table(self::TABLE)
                ->where('typo', '=', 1, not: true)
                ->delete();
        });

        Assert::same(
            md5((string)file_get_contents($this->dataPath())),
            $before,
        );
        Assert::same($this->db->table(self::TABLE)->count(), 3);
    }

    #[Test]
    public function unknownColumnInOrderByDistinctAndSelectColumn(): void
    {
        $this->expectKey('QUERY_UNKNOWN_COLUMN', function (): void {
            $this->db->table(self::TABLE)
                ->orderBy('typo')->selectAllByArray();
        });
        $this->expectKey('QUERY_UNKNOWN_COLUMN', function (): void {
            $this->db->table(self::TABLE)
                ->isDistinct('typo')->selectAllByArray();
        });
        $this->expectKey('QUERY_UNKNOWN_COLUMN', function (): void {
            $this->db->table(self::TABLE)->selectColumn('typo');
        });
    }

    #[Test]
    public function ghostRowMissingFieldBehavesAsNull(): void
    {
        file_put_contents(
            $this->dataPath(),
            '{"id":77,"name":"ghost","n":9,"price":1.0,"b":false,'
                . '"created":"2026-01-05 10:00:00"}' . "\n",
            FILE_APPEND,
        );
        $this->db->invalidateCache(self::TABLE);

        $rows = $this->db->table(self::TABLE)
            ->where('flag', '=', null)->selectAllByArray();
        $ids = array_column($rows, 'id');
        Assert::true(\in_array(77, $ids, true), 'ghost row must read as null');

        $rows = $this->db->table(self::TABLE)
            ->where('flag', '=', 7, not: true)->selectAllByArray();
        $ids = array_column($rows, 'id');
        Assert::true(\in_array(77, $ids, true));
    }

    #[Test]
    public function negativeLimitAndOffsetRejectedInBuilder(): void
    {
        $this->expectKey('INVALID_LIMIT', function (): void {
            $this->db->table(self::TABLE)->limit(-1);
        });
        $this->expectKey('INVALID_OFFSET', function (): void {
            $this->db->table(self::TABLE)->offset(-5);
        });
    }

    #[Test]
    public function negativeLimitAndOffsetRejectedInProviderSelect(): void
    {
        $this->expectKey('INVALID_LIMIT', function (): void {
            $this->db->select(self::TABLE, limit: -1);
        });
        $this->expectKey('INVALID_OFFSET', function (): void {
            $this->db->select(self::TABLE, offset: -1);
        });
    }

    #[Test]
    public function limitZeroAndOffsetZeroAreValid(): void
    {
        Assert::count(
            $this->db->table(self::TABLE)->limit(0)->selectAllByArray(),
            0,
        );
        Assert::count(
            $this->db->table(self::TABLE)->offset(0)->selectAllByArray(),
            3,
        );
    }

    #[Test]
    public function orderByDirectionIsCaseInsensitive(): void
    {
        $desc = array_column(
            $this->db->table(self::TABLE)
                ->orderBy('price', 'desc')->selectAllByArray(),
            'price',
        );

        foreach (['DESC', 'Desc'] as $direction) {
            Assert::same(
                array_column(
                    $this->db->table(self::TABLE)
                        ->orderBy('price', $direction)->selectAllByArray(),
                    'price',
                ),
                $desc,
                $direction,
            );
        }

        $asc = array_column(
            $this->db->table(self::TABLE)
                ->orderBy('n', 'asc')->selectAllByArray(),
            'n',
        );
        Assert::same(
            array_column(
                $this->db->table(self::TABLE)
                    ->orderBy('n', 'ASC')->selectAllByArray(),
                'n',
            ),
            $asc,
        );
    }

    #[Test]
    public function invalidOrderByDirectionRejected(): void
    {
        foreach (['descending', '', 'down'] as $direction) {
            $this->expectKey(
                'INVALID_SORT_DIRECTION',
                function () use ($direction): void {
                    $this->db->table(self::TABLE)
                        ->orderBy('price', $direction);
                },
            );
        }
    }

    #[Test]
    public function unknownOrderByColumnRejectedOnCountAndExists(): void
    {
        $this->expectKey('QUERY_UNKNOWN_COLUMN', function (): void {
            $this->db->table(self::TABLE)->orderBy('typo')->count();
        });
        $this->expectKey('QUERY_UNKNOWN_COLUMN', function (): void {
            $this->db->table(self::TABLE)->orderBy('typo')->exists();
        });
    }

    #[Test]
    public function limitZeroSelectOneReturnsNull(): void
    {
        Assert::null(
            $this->db->table(self::TABLE)->limit(0)->selectOneByArray(),
        );
        Assert::notNull(
            $this->db->table(self::TABLE)->limit(1)->selectOneByArray(),
        );
    }

    #[Test]
    public function brokenUtf8ConditionRejected(): void
    {
        $this->expectKey('INVALID_UTF8', function (): void {
            $this->db->table(self::TABLE)
                ->where('name', '=', "\xFF\xFE")->selectAllByArray();
        });
    }

    #[Test]
    public function monthConditionOutOfRangeRejected(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'months',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'm' => 'month'],
            indexes: [],
        ));

        $this->expectKey('NUMERIC_PART_OUT_OF_RANGE', function (): void {
            $this->db->table('months')
                ->where('m', '=', 13)->selectAllByArray();
        });
    }

    #[Test]
    public function unknownOperatorRejectedWithLocalizedKey(): void
    {
        $this->expectKey('INVALID_OPERATOR', function (): void {
            $this->db->table(self::TABLE)->where('n', '=<', 5);
        });
    }

    private function expectKey(string $errorKey, callable $fn): void
    {
        try {
            $fn();
            Assert::fail("expected StorageException {$errorKey}");
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), $errorKey);
        }
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
        return TempDir::root('jp-queryval-tests');
    }
}
