<?php

declare(strict_types=1);

namespace AV\JsonProvider\Engine;

use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Query\PartialSort;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Query\ValueComparator;

/**
 * Evaluates query conditions and ordering on decoded records: the row
 * filter of a condition list, value comparison under the comparison
 * mode, ordering with a bounded partial sort.
 *
 * @internal
 */
final class RecordMatcher
{
    /**
     * How many times the result must exceed the kept prefix before a bounded
     * selection is worth it instead of a full sort. Below this the native
     * usort wins; the value is where the two met in the sort benchmarks.
     */
    private const int PARTIAL_SORT_MARGIN = 4;

    public function __construct(
        private readonly Context $context,
    ) {
    }

    /**
     * Sorts records in place by the ordering rules (stable — usort in
     * PHP 8+).
     *
     * @param array<int,array<string,null|scalar>> $records
     * @param array<int,OrderBy>                   $ordering
     */
    public function sortByOrdering(
        array &$records,
        array $ordering,
        int | null $keep = null,
    ): void {
        /*
         * With a limit small against the result, only the first $keep records
         * are ever returned, so a bounded selection replaces the full sort
         * (n log k against n log n). The margin keeps it out of the way when
         * the limit approaches the row count: there the engine-level usort,
         * running in C, beats a heap driven from PHP.
         */
        if (
            $keep !== null
            && $keep > 0
            && $keep * self::PARTIAL_SORT_MARGIN <= \count($records)
        ) {
            $records = PartialSort::top(
                $records,
                fn (array $a, array $b): int => $this->compareRecords(
                    $a,
                    $b,
                    $ordering,
                ),
                $keep,
            );

            return;
        }

        usort(
            $records,
            fn (array $a, array $b): int => $this->compareRecords(
                $a,
                $b,
                $ordering,
            ),
        );
    }

    /**
     * One test of a record against all the conditions, built once per
     * query (FilterCondition::predicate) and run per row.
     *
     * @param array<int,FilterCondition> $conditions
     *
     * @return \Closure(array<mixed>): bool
     */
    public function conditionFilter(array $conditions): \Closure
    {
        $tests = [];

        foreach ($conditions as $condition) {
            $tests[] = $condition->predicate($this->context->comparisonMode);
        }

        if (\count($tests) === 1) {
            return $tests[0];
        }

        return static function (array $record) use ($tests): bool {
            foreach ($tests as $test) {
                if (!$test($record)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * Compares two records field by field under the ordering rules.
     *
     * @param array<string,null|scalar> $a
     * @param array<string,null|scalar> $b
     * @param array<int,OrderBy>        $ordering
     */
    private function compareRecords(
        array $a,
        array $b,
        array $ordering,
    ): int {
        foreach ($ordering as $order) {
            $cmp = $this->compareValues(
                $a[$order->field] ?? null,
                $b[$order->field] ?? null,
            );

            if ($cmp !== 0) {
                return $order->direction === SortDirectionEnum::ASC
                    ? $cmp
                    : -$cmp;
            }
        }

        return 0;
    }

    private function compareValues(
        bool | float | int | string | null $a,
        bool | float | int | string | null $b,
    ): int {
        return ValueComparator::compare($a, $b, $this->context->comparisonMode);
    }
}
