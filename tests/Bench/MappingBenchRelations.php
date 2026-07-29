<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Bench;

use AV\JsonProvider\Tests\Support\Dto\MapCategoryDto;
use AV\JsonProvider\Tests\Support\Dto\MapRowScalarDto;
use AV\JsonProvider\Tests\Support\MappingFixture;
use Testo\Assert\ExpectNoAssertions;
use Testo\Bench;

/**
 * What declared relations cost, and what a join costs on each surface.
 *
 * Unlike the other suites there is no stub anywhere: every benchmark compares
 * two REAL operations that differ in exactly one property.
 *
 *  - insertWithFk writes into `map_children`, whose `row_id` is a declared
 *    belongsTo; the "no-fk" side writes the identical row into `map_plain`,
 *    which has no relation. The delta is the existence probe.
 *  - deleteCascade removes a parent that owns CHILDREN / CHILD_PARENTS
 *    children through ON DELETE CASCADE; the "no-cascade" side removes a
 *    parent with no children at all, so it still pays the FK probe and the
 *    table rewrite but no cascade.
 *  - joinTwoStep resolves rows to their categories in two queries (fetch
 *    rows, then fetch the parents by the collected keys) — once through DTOs
 *    on both sides, once through arrays.
 *
 * The mutating benchmarks walk forward through the fixture: each iteration
 * deletes the NEXT parent, so no iteration is a no-op repeat of the last one
 * — the trap that makes deleteMatching in LoadBenchWrite report a ±77%
 * spread. They do leave the fixture slightly smaller than they found it (a
 * handful of rows out of ROWS), which the read benchmarks tolerate.
 */
final class MappingBenchRelations
{
    /** Next parent WITH children to delete (ids 1..CHILD_PARENTS own rows). */
    private static int $cascadeParent = 1;

    /** Next parent WITHOUT children to delete. */
    private static int $childlessParent = MappingFixture::CHILD_PARENTS + 1;

    #[Bench(
        callables: ['no-fk' => [self::class, 'insertWithoutFk']],
        calls: 2,
        iterations: 6,
    )]
    #[ExpectNoAssertions]
    public static function insertWithFk(): int
    {
        return MappingFixture::db()
            ->table(MappingFixture::CHILDREN_TABLE)
            ->insertByArray(['row_id' => 10, 'payload' => 'appended']);
    }

    public static function insertWithoutFk(): int
    {
        return MappingFixture::db()
            ->table(MappingFixture::PLAIN_TABLE)
            ->insertByArray(['row_id' => 10, 'payload' => 'appended']);
    }

    #[Bench(
        callables: ['no-cascade' => [self::class, 'deleteChildless']],
        warmup: 0,
        calls: 1,
        iterations: 3,
    )]
    #[ExpectNoAssertions]
    public static function deleteCascade(): int
    {
        MappingFixture::db()
            ->table(MappingFixture::ROWS_TABLE)
            ->where('id', '=', self::$cascadeParent++)
            ->delete();

        return 0;
    }

    public static function deleteChildless(): int
    {
        MappingFixture::db()
            ->table(MappingFixture::ROWS_TABLE)
            ->where('id', '=', self::$childlessParent++)
            ->delete();

        return 0;
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'joinTwoStepArray']],
        calls: 2,
        iterations: 8,
    )]
    #[ExpectNoAssertions]
    public static function joinTwoStep(): int
    {
        $db = MappingFixture::bind(MapRowScalarDto::class);
        MappingFixture::bindCategories();

        $rows = iterator_to_array(
            $db->table(MappingFixture::ROWS_TABLE)
                ->where('bucket', '<', 10)
                ->selectAll(),
        );

        $keys = [];

        foreach ($rows as $row) {
            \assert($row instanceof MapRowScalarDto);
            $keys[$row->categoryId] = true;
        }

        $parents = [];

        foreach (
            $db->table(MappingFixture::CATEGORIES_TABLE)
                ->where('id', 'IN', array_keys($keys))
                ->selectAll() as $parent
        ) {
            \assert($parent instanceof MapCategoryDto);
            $parents[$parent->id] = $parent;
        }

        $matched = 0;

        foreach ($rows as $row) {
            if (isset($parents[$row->categoryId])) {
                $matched++;
            }
        }

        return $matched;
    }

    public static function joinTwoStepArray(): int
    {
        $db = MappingFixture::db();

        $rows = $db->table(MappingFixture::ROWS_TABLE)
            ->where('bucket', '<', 10)
            ->selectAllByArray();

        $keys = [];

        foreach ($rows as $row) {
            $keys[(int)$row['category_id']] = true;
        }

        $parents = [];

        foreach (
            $db->table(MappingFixture::CATEGORIES_TABLE)
                ->where('id', 'IN', array_keys($keys))
                ->selectAllByArray() as $parent
        ) {
            $parents[(int)$parent['id']] = $parent;
        }

        $matched = 0;

        foreach ($rows as $row) {
            if (isset($parents[(int)$row['category_id']])) {
                $matched++;
            }
        }

        return $matched;
    }
}
