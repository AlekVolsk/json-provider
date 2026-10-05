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
     * Position of the first entry whose key is >= $partKey, searching from
     * $from — a position at or before that entry.
     */
    public function lowerBound(string $partKey, int $from = 0): int;

    /**
     * Position past the run of entries whose key starts with $partKey,
     * searching from $from — a position at or before the end of the run.
     */
    public function upperBound(string $partKey, int $from = 0): int;

    /**
     * Data line numbers of the entries in [$from, $to).
     *
     * @return list<int>
     */
    public function lines(int $from, int $to): array;

    /**
     * The keys of the entries lines() returned, by data line.
     *
     * @return array<int,string>
     */
    public function keys(): array;

    /**
     * The key of the entry pointing at a data line that lines() returned,
     * or null when none did.
     */
    public function keyOf(int $line): string | null;
}
