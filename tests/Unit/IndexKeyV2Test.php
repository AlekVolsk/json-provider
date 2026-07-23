<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Index\IndexKey;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use Testo\Assert;
use Testo\Test;

/**
 * Tests for the v2 index key encoding:
 *
 *  - strcmp order of encoded keys matches the engine's value order inside
 *    every type category (strings bytewise incl. NUL/0x01 bytes and long
 *    Cyrillic, ints beyond 2^53, floats, null/bool);
 *  - key(99) === key(99.0) and key(-0.0) === key(0.0);
 *  - DESC inverts the order, including prefix/longer string pairs;
 *  - composite keys are injective: component boundaries never blend;
 *  - no truncation of long strings; NAN/INF throw.
 */
final class IndexKeyV2Test
{
    #[Test]
    public function stringOrderMatchesStrcmp(): void
    {
        $values = [
            '',
            "\x00",
            "\x00\x01",
            "\x01",
            '0',
            '10',
            '100',
            '9',
            'a',
            str_repeat('a', 40),
            str_repeat('a', 40) . 'b',
            'ab',
            'Привет',
            'Привёт',
            'яблоко',
            str_repeat('я', 30),
        ];

        $this->assertOrderPreserved($values, SortDirectionEnum::ASC);
    }

    #[Test]
    public function longStringsAreNotTruncated(): void
    {
        $prefix = str_repeat('x', 64);

        $keyA = $this->key($prefix . 'a', SortDirectionEnum::ASC);
        $keyB = $this->key($prefix . 'b', SortDirectionEnum::ASC);

        Assert::true($keyA !== $keyB);
    }

    #[Test]
    public function intOrderSurvivesDoublePrecisionLoss(): void
    {
        $values = [
            PHP_INT_MIN,
            PHP_INT_MIN + 1,
            -(2 ** 53) - 1,
            -(2 ** 53),
            -1,
            0,
            1,
            2 ** 53,
            2 ** 53 + 1,
            2 ** 53 + 2,
            2 ** 62,
            2 ** 62 + 1,
            PHP_INT_MAX - 1,
            PHP_INT_MAX,
        ];

        $this->assertOrderPreserved($values, SortDirectionEnum::ASC);
    }

    #[Test]
    public function floatOrderMatchesNumericOrder(): void
    {
        $values = [
            -PHP_FLOAT_MAX,
            -1.5,
            -PHP_FLOAT_MIN,
            0.0,
            PHP_FLOAT_MIN,
            0.1,
            0.3,
            1.0,
            1.5,
            99.0,
            PHP_FLOAT_MAX,
        ];

        $this->assertOrderPreserved($values, SortDirectionEnum::ASC);
    }

    #[Test]
    public function intAndFloatOfSameValueShareOneKey(): void
    {
        Assert::same(
            $this->key(99, SortDirectionEnum::ASC),
            $this->key(99.0, SortDirectionEnum::ASC),
        );
        Assert::same(
            $this->key(0, SortDirectionEnum::ASC),
            $this->key(0.0, SortDirectionEnum::ASC),
        );
    }

    #[Test]
    public function negativeZeroSharesKeyWithZero(): void
    {
        Assert::same(
            $this->key(-0.0, SortDirectionEnum::ASC),
            $this->key(0.0, SortDirectionEnum::ASC),
        );
    }

    #[Test]
    public function crossTypeTagOrderIsFixed(): void
    {
        $keys = [
            $this->key(null, SortDirectionEnum::ASC),
            $this->key(false, SortDirectionEnum::ASC),
            $this->key(true, SortDirectionEnum::ASC),
            $this->key(-5, SortDirectionEnum::ASC),
            $this->key('a', SortDirectionEnum::ASC),
        ];

        $sorted = $keys;
        sort($sorted, SORT_STRING);

        Assert::same($sorted, $keys);
    }

    #[Test]
    public function descInvertsOrderIncludingPrefixPairs(): void
    {
        $values = ['', 'a', 'ab', 'abc', 'b', '10', '9'];

        usort($values, static fn (string $a, string $b): int => strcmp($a, $b));

        $descKeys = array_map(
            fn (string $v): string => $this->key($v, SortDirectionEnum::DESC),
            $values,
        );

        $sorted = $descKeys;
        sort($sorted, SORT_STRING);

        Assert::same($sorted, array_reverse($descKeys));
    }

    #[Test]
    public function descInvertsNumericOrder(): void
    {
        $values = [-10, -1, 0, 1, 2 ** 53 + 1, PHP_INT_MAX];

        $this->assertOrderPreserved($values, SortDirectionEnum::DESC, true);
    }

    #[Test]
    public function compositeComponentBoundariesDoNotBlend(): void
    {
        $schema = new IndexSchema(
            name: 'idx',
            fields: [
                new IndexFieldSchema('a', SortDirectionEnum::ASC),
                new IndexFieldSchema('b', SortDirectionEnum::ASC),
            ],
        );

        $pairs = [
            [['a' => 'ab', 'b' => 'c'], ['a' => 'a', 'b' => 'bc']],
            [['a' => '', 'b' => 'x'], ['a'      => 'x', 'b' => '']],
            [['a' => "a\x00", 'b' => 'b'], ['a' => 'a', 'b' => "\x00b"]],
            [['a' => null, 'b' => 'x'], ['a'    => 'x', 'b' => null]],
            [['a' => 1, 'b' => 'x'], ['a'       => '1', 'b' => 'x']],
        ];

        foreach ($pairs as $i => [$left, $right]) {
            Assert::true(
                IndexKey::build($left, $schema)
                    !== IndexKey::build($right, $schema),
                "pair #{$i} must not collide",
            );
        }
    }

    #[Test]
    public function compositeKeysAreInjectiveFuzz(): void
    {
        $schema = new IndexSchema(
            name: 'idx',
            fields: [
                new IndexFieldSchema('a', SortDirectionEnum::ASC),
                new IndexFieldSchema('b', SortDirectionEnum::DESC),
            ],
        );

        $pool = [
            null, false, true, 0, 1, -1, 2 ** 53 + 1, 0.5, -0.5,
            '', 'a', 'ab', "a\x00", "a\x01", "\x00", "\x01\x01", '10', '9',
        ];

        $seen = [];

        foreach ($pool as $a) {
            foreach ($pool as $b) {
                $key = IndexKey::build(['a' => $a, 'b' => $b], $schema);
                $tuple = var_export([$a, $b], true);

                if (isset($seen[$key])) {
                    Assert::same(
                        $seen[$key],
                        $tuple,
                        'distinct tuples must not share a key',
                    );
                }

                $seen[$key] = $tuple;
            }
        }

        Assert::count($seen, \count($pool) ** 2);
    }

    #[Test]
    public function nonFiniteFloatThrows(): void
    {
        foreach ([INF, -INF, NAN] as $value) {
            try {
                $this->key($value, SortDirectionEnum::ASC);
                Assert::fail('non-finite float must not be indexable');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'INDEX_KEY_NON_FINITE');
            }
        }
    }

    #[Test]
    public function missingFieldEncodesAsNull(): void
    {
        $schema = new IndexSchema(
            name: 'idx',
            fields: [new IndexFieldSchema('f', SortDirectionEnum::ASC)],
        );

        Assert::same(
            IndexKey::build([], $schema),
            IndexKey::build(['f' => null], $schema),
        );
    }

    // -- helpers -----------------------------------------------------------

    /**
     * @param array<int,null|scalar> $values values in ascending engine order
     */
    private function assertOrderPreserved(
        array $values,
        SortDirectionEnum $direction,
        bool $expectReversed = false,
    ): void {
        $keys = array_map(
            fn (bool | float | int | string | null $v): string => $this->key(
                $v,
                $direction,
            ),
            $values,
        );

        $sorted = $keys;
        sort($sorted, SORT_STRING);

        Assert::same(
            $sorted,
            $expectReversed ? array_reverse($keys) : $keys,
        );
        Assert::count(array_unique($keys), \count($values));
    }

    private function key(
        bool | float | int | string | null $value,
        SortDirectionEnum $direction,
    ): string {
        return IndexKey::buildFromValue(
            $value,
            new IndexFieldSchema('f', $direction),
        );
    }
}
