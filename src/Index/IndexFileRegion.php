<?php

declare(strict_types=1);

namespace AV\JsonProvider\Index;

use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Exception\Locale\LocaleInterface;
use AV\JsonProvider\Schema\IndexSchema;

/**
 * The sorted head of an index file, searched in place: positions are byte
 * offsets of line starts, a bound is a binary search that reads about
 * log2(n) lines, a range is read line by line. The file is never read
 * whole.
 *
 * Every line read is checked — a {key, line} pair, a well-formed key, a
 * line reference inside the data file — and so is the key order along the
 * way: a probe must fall between the keys already seen on either side of
 * the bound, and a range must not decrease. A violation fails through
 * $fail (INDEX_UNRELIABLE). Corruption off the path of a lookup is left to
 * validate(), which reads the whole file.
 */
final class IndexFileRegion implements SortedIndexEntries
{
    /** @var resource */
    private $handle;

    /** @var array<int,string> keys of the lines returned so far */
    private array $keys = [];

    /**
     * @param \Closure(LocaleInterface, string...): never $fail
     */
    public function __construct(
        string $path,
        private readonly int $bytes,
        private readonly IndexSchema $index,
        private readonly int $lineCount,
        private readonly \Closure $fail,
    ) {
        $handle = is_file($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            ($this->fail)(JsonProviderErrorEn::IndexFileMissing);
        }

        $this->handle = $handle;

        if ($bytes > 0) {
            fseek($this->handle, $bytes - 1);

            if (fread($this->handle, 1) !== "\n") {
                ($this->fail)(JsonProviderErrorEn::IndexEntryMalformed);
            }
        }
    }

    public function __destruct()
    {
        fclose($this->handle);
    }

    public function start(): int
    {
        return 0;
    }

    public function end(): int
    {
        return $this->bytes;
    }

    public function lowerBound(string $partKey): int
    {
        return $this->firstWhere(
            static fn (string $key): bool => strcmp($key, $partKey) >= 0,
        );
    }

    public function upperBound(string $partKey): int
    {
        return $this->firstWhere(
            static fn (string $key): bool => !str_starts_with($key, $partKey)
                && strcmp($key, $partKey) > 0,
        );
    }

    public function lines(int $from, int $to): array
    {
        $lines = [];
        $previous = null;
        $position = $from;

        while ($position < $to) {
            [$key, $line, $position] = $this->entryAt($position);

            if ($previous !== null && strcmp($previous, $key) > 0) {
                ($this->fail)(JsonProviderErrorEn::IndexOrderBroken);
            }

            $previous = $key;
            $lines[] = $line;
            $this->keys[$line] = $key;
        }

        return $lines;
    }

    public function keyOf(int $line): string | null
    {
        return $this->keys[$line] ?? null;
    }

    /**
     * Parses and checks one raw index line (with its newline): a {key,
     * line} pair, a well-formed key, a line reference inside the data file.
     *
     * @param \Closure(LocaleInterface, string...): never $fail
     *
     * @return array{key:string, line:int}
     */
    public static function parse(
        string $raw,
        IndexSchema $index,
        int $lineCount,
        \Closure $fail,
    ): array {
        if (!str_ends_with($raw, "\n")) {
            $fail(JsonProviderErrorEn::IndexEntryMalformed);
        }

        $entry = json_decode(substr($raw, 0, -1), true);
        $key = \is_array($entry) ? ($entry['key'] ?? null) : null;
        $line = \is_array($entry) ? ($entry['line'] ?? null) : null;

        if (!\is_string($key) || !\is_int($line)) {
            $fail(JsonProviderErrorEn::IndexEntryMalformed);
        }

        if ($line < 0 || $line >= $lineCount) {
            $fail(JsonProviderErrorEn::IndexBrokenPermutation);
        }

        if (!IndexKey::wellFormed($key, $index)) {
            $fail(JsonProviderErrorEn::IndexKeyMalformed);
        }

        return ['key' => $key, 'line' => $line];
    }

    /**
     * The first line start whose key satisfies the monotone $matches (false
     * on a prefix of the sorted keys, true on the rest), or end() when no
     * key does.
     *
     * @param \Closure(string): bool $matches
     */
    private function firstWhere(\Closure $matches): int
    {
        $lo = 0;
        $hi = $this->bytes;
        $floor = null;
        $ceiling = null;

        while ($lo < $hi) {
            $mid = $lo + intdiv($hi - $lo, 2);
            $probe = $this->lineStartFrom($mid, $hi);

            if ($probe === $hi) {
                $probe = $lo;
            }

            [$key, , $next] = $this->entryAt($probe);

            if (
                ($floor !== null && strcmp($floor, $key) > 0)
                || ($ceiling !== null && strcmp($key, $ceiling) > 0)
            ) {
                ($this->fail)(JsonProviderErrorEn::IndexOrderBroken);
            }

            if ($matches($key)) {
                $hi = $probe;
                $ceiling = $key;
            } else {
                $lo = $next;
                $floor = $key;
            }
        }

        return $lo;
    }

    /**
     * The first line start at or after $offset, capped at $limit (itself a
     * line start or the end of the region).
     */
    private function lineStartFrom(int $offset, int $limit): int
    {
        if ($offset <= 0) {
            return 0;
        }

        fseek($this->handle, $offset - 1);
        $rest = fgets($this->handle);

        return $rest === false
            ? $limit
            : min($limit, $offset - 1 + \strlen($rest));
    }

    /**
     * The key, data line and next position of the entry at a line start.
     *
     * @return array{string, int, int}
     */
    private function entryAt(int $position): array
    {
        fseek($this->handle, $position);
        $raw = fgets($this->handle);

        if ($raw === false || $position + \strlen($raw) > $this->bytes) {
            ($this->fail)(JsonProviderErrorEn::IndexEntryMalformed);
        }

        $entry = self::parse($raw, $this->index, $this->lineCount, $this->fail);

        return [$entry['key'], $entry['line'], $position + \strlen($raw)];
    }
}
