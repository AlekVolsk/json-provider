<?php

declare(strict_types=1);

namespace AV\JsonProvider\Index;

/**
 * Sorted index entries held in memory; positions are array offsets.
 */
final class IndexEntryList implements SortedIndexEntries
{
    /** @var array<int,string> keys of the lines returned so far */
    private array $keys = [];

    /**
     * @param array<int,array{key:string,line:int}> $entries sorted by key
     */
    public function __construct(private readonly array $entries)
    {
    }

    public function start(): int
    {
        return 0;
    }

    public function end(): int
    {
        return \count($this->entries);
    }

    public function lowerBound(string $partKey): int
    {
        $lo = 0;
        $hi = \count($this->entries);

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);

            if (strcmp($this->entries[$mid]['key'], $partKey) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    public function upperBound(string $partKey): int
    {
        $lo = 0;
        $hi = \count($this->entries);

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $key = $this->entries[$mid]['key'];
            $cmp = str_starts_with($key, $partKey)
                ? 0
                : strcmp($key, $partKey);

            if ($cmp <= 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    public function lines(int $from, int $to): array
    {
        $lines = [];

        for ($i = $from; $i < $to; $i++) {
            $lines[] = $this->entries[$i]['line'];
            $this->keys[$this->entries[$i]['line']] = $this->entries[$i]['key'];
        }

        return $lines;
    }

    public function keyOf(int $line): string | null
    {
        return $this->keys[$line] ?? null;
    }
}
