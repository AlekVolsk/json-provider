<?php

declare(strict_types=1);

namespace AV\JsonProvider\Index;

/**
 * Index entries sorted by key, addressed by opaque positions: the search
 * primitives IndexManager runs every lookup through, whether the entries
 * are an array in memory or the sorted head of an index file.
 *
 * Bounds compare the first key part only: the part encoding is prefix-free,
 * so an entry whose first part equals the target starts with the target's
 * key and compares >= to it.
 */
interface SortedIndexEntries
{
    /**
     * Position of the first entry.
     */
    public function start(): int;

    /**
     * Position past the last entry.
     */
    public function end(): int;

    /**
     * Position of the first entry whose key is >= $partKey.
     */
    public function lowerBound(string $partKey): int;

    /**
     * Position past the run of entries whose key starts with $partKey.
     */
    public function upperBound(string $partKey): int;

    /**
     * Data line numbers of the entries in [$from, $to).
     *
     * @return list<int>
     */
    public function lines(int $from, int $to): array;

    /**
     * The key of the entry pointing at a data line that lines() returned,
     * or null when none did.
     */
    public function keyOf(int $line): string | null;
}
