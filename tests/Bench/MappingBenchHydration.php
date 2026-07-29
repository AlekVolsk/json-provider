<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Bench;

use AV\JsonProvider\Tests\Support\Dto\MapRowEnumDto;
use AV\JsonProvider\Tests\Support\Dto\MapRowFullDto;
use AV\JsonProvider\Tests\Support\Dto\MapRowNarrowDto;
use AV\JsonProvider\Tests\Support\Dto\MapRowScalarDto;
use AV\JsonProvider\Tests\Support\MappingFixture;
use Testo\Assert\ExpectNoAssertions;
use Testo\Bench;

/**
 * What the object surface costs on top of the array surface.
 *
 * Every benchmark runs ONE query twice: the marked method (reported as
 * "current") takes it through selectAll() and materializes DTOs, the
 * "api:array" callable takes the very same query through selectAllByArray().
 * Both pay the identical file read, decode and filter — the delta is
 * hydration and nothing else.
 *
 * Two axes are separated on purpose:
 *
 *  - ROW COUNT (hydrateFull100 → 1k → 10k → fieldsFull100k). The mapper is
 *    charged per ROW RETURNED, not per row scanned, so on a ROWS-row table a
 *    query that returns a hundred rows pays almost nothing while a full scan
 *    pays for every one of them. This is the axis the old LoadBenchDto never
 *    varied — nearly all of its benchmarks returned a hundred rows, and it
 *    duly reported no difference.
 *
 *  - FIELD SHAPE (fieldsNarrow100k → fieldsScalar100k → fieldsEnum100k →
 *    fieldsFull100k), each over the whole table. Every step adds exactly one
 *    kind of work: more scalar fields, then one backed-enum conversion per
 *    row, then two DateTimeImmutable parses per row. The ladder runs at full
 *    table size because at 10k rows the first three steps sat inside the
 *    run-to-run spread and could not be told apart.
 *
 * lazyFirst10 asks whether the generator returned by selectAll() buys anything
 * when the consumer stops after ten rows. It does not: select() materializes
 * the whole result as an array first and only the HYDRATION walks it lazily
 * (see JsonTable::selectAll), so the read is already paid in full by the time
 * the first object exists. Ten objects instead of a hundred thousand saves
 * less than the generator machinery costs, and the object path comes out a few
 * percent behind the array one. The benchmark stays because that is a claim
 * worth re-checking whenever the read path changes.
 */
final class MappingBenchHydration
{
    #[Bench(
        callables: ['api:array' => [self::class, 'narrowSelectivityArray']],
        calls: 3,
        iterations: 10,
    )]
    #[ExpectNoAssertions]
    public static function hydrateFull100(): int
    {
        return \count(iterator_to_array(
            MappingFixture::bind(MapRowFullDto::class)
                ->table(MappingFixture::ROWS_TABLE)
                ->where('bucket', '=', MappingFixture::narrowBucket())
                ->selectAll(),
        ));
    }

    public static function narrowSelectivityArray(): int
    {
        return \count(
            MappingFixture::db()
                ->table(MappingFixture::ROWS_TABLE)
                ->where('bucket', '=', MappingFixture::narrowBucket())
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'thousandRowsArray']],
        calls: 3,
        iterations: 10,
    )]
    #[ExpectNoAssertions]
    public static function hydrateFull1k(): int
    {
        return \count(iterator_to_array(
            MappingFixture::bind(MapRowFullDto::class)
                ->table(MappingFixture::ROWS_TABLE)
                ->where('bucket', '<', 10)
                ->selectAll(),
        ));
    }

    public static function thousandRowsArray(): int
    {
        return \count(
            MappingFixture::db()
                ->table(MappingFixture::ROWS_TABLE)
                ->where('bucket', '<', 10)
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'tenThousandRowsArray']],
        calls: 2,
        iterations: 8,
    )]
    #[ExpectNoAssertions]
    public static function hydrateFull10k(): int
    {
        return self::hydrateDecile(MapRowFullDto::class);
    }

    public static function tenThousandRowsArray(): int
    {
        return \count(
            MappingFixture::db()
                ->table(MappingFixture::ROWS_TABLE)
                ->where('decile', '=', 3)
                ->selectAllByArray(),
        );
    }

    public static function wholeTableArray(): int
    {
        return \count(
            MappingFixture::db()
                ->table(MappingFixture::ROWS_TABLE)
                ->selectAllByArray(),
        );
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'wholeTableArray']],
        calls: 1,
        iterations: 6,
    )]
    #[ExpectNoAssertions]
    public static function fieldsNarrow100k(): int
    {
        return self::hydrateWholeTable(MapRowNarrowDto::class);
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'wholeTableArray']],
        calls: 1,
        iterations: 6,
    )]
    #[ExpectNoAssertions]
    public static function fieldsScalar100k(): int
    {
        return self::hydrateWholeTable(MapRowScalarDto::class);
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'wholeTableArray']],
        calls: 1,
        iterations: 6,
    )]
    #[ExpectNoAssertions]
    public static function fieldsEnum100k(): int
    {
        return self::hydrateWholeTable(MapRowEnumDto::class);
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'wholeTableArray']],
        calls: 1,
        iterations: 6,
    )]
    #[ExpectNoAssertions]
    public static function fieldsFull100k(): int
    {
        return self::hydrateWholeTable(MapRowFullDto::class);
    }

    #[Bench(
        callables: ['api:array' => [self::class, 'firstTenArray']],
        warmup: 0,
        calls: 1,
        iterations: 6,
    )]
    #[ExpectNoAssertions]
    public static function lazyFirst10(): int
    {
        $seen = 0;

        foreach (
            MappingFixture::bind(MapRowFullDto::class)
                ->table(MappingFixture::ROWS_TABLE)
                ->selectAll() as $row
        ) {
            \assert($row instanceof MapRowFullDto);

            if (++$seen === 10) {
                break;
            }
        }

        return $seen;
    }

    public static function firstTenArray(): int
    {
        $rows = MappingFixture::db()
            ->table(MappingFixture::ROWS_TABLE)
            ->selectAllByArray();

        return \count(\array_slice($rows, 0, 10));
    }

    /**
     * The whole table, hydrated through whichever DTO the caller binds. Only
     * the binding differs between the four field-shape benchmarks, and at
     * ROWS rows the per-row cost is large enough to separate them.
     *
     * @param class-string $dto
     */
    private static function hydrateWholeTable(string $dto): int
    {
        return \count(iterator_to_array(
            MappingFixture::bind($dto)
                ->table(MappingFixture::ROWS_TABLE)
                ->selectAll(),
        ));
    }

    /**
     * The shared 10k-row query, the third point of the selectivity curve.
     *
     * @param class-string $dto
     */
    private static function hydrateDecile(string $dto): int
    {
        return \count(iterator_to_array(
            MappingFixture::bind($dto)
                ->table(MappingFixture::ROWS_TABLE)
                ->where('decile', '=', 3)
                ->selectAll(),
        ));
    }
}
