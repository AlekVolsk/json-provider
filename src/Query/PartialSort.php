<?php

declare(strict_types=1);

namespace AV\JsonProvider\Query;

/**
 * Selects the smallest K records under a comparator without ordering the rest.
 *
 * A query that ends in `ORDER BY ... LIMIT k` only needs the first k records
 * in order; sorting everything to then slice k of them is `n log n` work where
 * `n log k` suffices. This walks the records once against a bounded max-heap:
 * a record is admitted only while the heap is short of k, and after that only
 * when it beats the current worst — a single comparison rejects the common
 * case.
 *
 * The heap holds the k best seen so far with the WORST of them at the root
 * (that is what makes the rejection test one comparison), so the result is
 * sorted at the end — over k records, not over n.
 *
 * Ties keep their input order. A heap is not stable on its own, so each entry
 * carries its position and the comparator falls back to it: without that, rows
 * with equal sort keys would come back in a different order than a full sort
 * returns, and ORDER BY would stop being deterministic.
 *
 * @phpstan-type Row array<string,null|scalar>
 * @phpstan-type Entry array{Row, int}
 * @phpstan-type Ranker \Closure(Entry, Entry): int
 *
 * @internal
 */
final class PartialSort
{
    /**
     * @param array<int,Row>          $records
     * @param \Closure(Row, Row): int $comparator
     * @param int<1,max>              $keep
     *
     * @return array<int,Row>
     */
    public static function top(
        array $records,
        \Closure $comparator,
        int $keep,
    ): array {
        $ranked = static function (array $x, array $y) use ($comparator): int {
            /** @var Entry $x */
            /** @var Entry $y */
            $cmp = $comparator($x[0], $y[0]);

            return $cmp !== 0 ? $cmp : $x[1] <=> $y[1];
        };

        /** @var array<int,Entry> $heap */
        $heap = [];
        $size = 0;
        $position = 0;

        foreach ($records as $record) {
            $entry = [$record, $position];
            $position++;

            if ($size < $keep) {
                $heap[$size] = $entry;
                self::siftUp($heap, $size, $ranked);
                $size++;

                continue;
            }

            if ($ranked($entry, $heap[0]) < 0) {
                $heap[0] = $entry;
                self::siftDown($heap, $size, $ranked);
            }
        }

        usort($heap, $ranked);

        return array_column($heap, 0);
    }

    /**
     * @param array<int,Entry> $heap
     * @param Ranker           $ranked
     */
    private static function siftUp(
        array &$heap,
        int $index,
        \Closure $ranked,
    ): void {
        while ($index > 0) {
            $parent = $index - 1 >> 1;

            if ($ranked($heap[$index], $heap[$parent]) <= 0) {
                return;
            }

            [$heap[$index], $heap[$parent]] = [$heap[$parent], $heap[$index]];
            $index = $parent;
        }
    }

    /**
     * @param array<int,Entry> $heap
     * @param Ranker           $ranked
     */
    private static function siftDown(
        array &$heap,
        int $size,
        \Closure $ranked,
    ): void {
        $index = 0;

        while (true) {
            $left = ($index << 1) + 1;

            if ($left >= $size) {
                return;
            }

            $largest = $left;
            $right = $left + 1;

            if ($right < $size && $ranked($heap[$right], $heap[$left]) > 0) {
                $largest = $right;
            }

            if ($ranked($heap[$largest], $heap[$index]) <= 0) {
                return;
            }

            [$heap[$index], $heap[$largest]] = [$heap[$largest], $heap[$index]];
            $index = $largest;
        }
    }
}
