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
 * Every line read is checked — a {key, line} pair, a line reference inside
 * the data file — and so is the key order along the way: a probe must fall
 * between the keys already seen on either side of the bound, and a range
 * must not decrease. A probe's key is checked for form too; the keys of a
 * range are checked by IndexManager::verifyRecords() against the records
 * they point at. A violation fails through $fail (INDEX_UNRELIABLE).
 * Corruption off the path of a lookup is left to validate(), which reads
 * the whole file.
 */
final class IndexFileRegion implements SortedIndexEntries
{
    /** @var resource */
    private $handle;

    /** @var array<int,string> keys of the lines returned so far */
    private array $keys = [];

    /** @var array<int,array{string, int, int}> entries probed, by position */
    private array $probes = [];

    /** @var array<int,int> the line start found at or after an offset */
    private array $starts = [];

    /** entries the runs may hold before lines() stops reading, or null */
    private int | null $budget = null;

    /** entries the runs read so far are estimated to hold */
    private int $spent = 0;

    /**
     * @param \Closure(LocaleInterface, string...): never $fail
     */
    public function __construct(
        string $path,
        private readonly int $bytes,
        private readonly IndexSchema $index,
        private readonly int $lineCount,
        private readonly \Closure $fail,
        private readonly int $count = 0,
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

    public function lowerBound(string $partKey, int $from = 0): int
    {
        return $this->firstWhere(
            static fn (string $key): bool => strcmp($key, $partKey) >= 0,
            $from,
        );
    }

    public function upperBound(string $partKey, int $from = 0): int
    {
        return $this->firstWhere(
            static fn (string $key): bool => !str_starts_with($key, $partKey)
                && strcmp($key, $partKey) > 0,
            $from,
        );
    }

    /**
     * The run is read with one call and split into lines. Each entry is
     * checked for its {key, line} shape, its line reference and the key
     * order; the form of its key is not: every line a lookup returns is
     * checked against its record by IndexManager::verifyRecords(), which
     * rebuilds the key from the record — a key equal to that one is well
     * formed.
     */
    public function lines(int $from, int $to): array
    {
        if ($from >= $to || $this->overBudget()) {
            return [];
        }

        if ($this->budget !== null && $this->bytes > 0) {
            $this->spent += intdiv(($to - $from) * $this->count, $this->bytes);

            if ($this->overBudget()) {
                return [];
            }
        }

        $raw = stream_get_contents($this->handle, $to - $from, $from);

        if (
            !\is_string($raw)
            || \strlen($raw) !== $to - $from
            || !str_ends_with($raw, "\n")
        ) {
            ($this->fail)(JsonProviderErrorEn::IndexEntryMalformed);
        }

        $bulk = $this->canonicalRun($raw);

        if ($bulk !== null) {
            return $bulk;
        }

        $lines = [];
        $previous = null;

        foreach (explode("\n", substr($raw, 0, -1)) as $text) {
            [$key, $line] = self::fields(
                $text,
                $this->lineCount,
                $this->fail,
            );

            if ($previous !== null && strcmp($previous, $key) > 0) {
                ($this->fail)(JsonProviderErrorEn::IndexOrderBroken);
            }

            $previous = $key;
            $lines[] = $line;
            $this->keys[$line] = $key;
        }

        return $lines;
    }

    public function keys(): array
    {
        return $this->keys;
    }

    /**
     * Caps how many entries the runs of a lookup may hold: before reading
     * a run, lines() estimates its entries from its byte length and the
     * head's average entry length; once the runs read would exceed the cap
     * it reads nothing more and the lookup is over budget (overBudget()).
     * The estimate is close for keys of fixed length (numbers) and rough
     * for strings; it only decides whether the lookup is worth finishing.
     * The lines of a lookup that went over budget are incomplete: a caller
     * that sets a limit checks overBudget() before using them.
     */
    public function limit(int $entries): void
    {
        $this->budget = $entries;
    }

    public function overBudget(): bool
    {
        return $this->budget !== null && $this->spent > $this->budget;
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

        [$key, $line] = self::fields(substr($raw, 0, -1), $lineCount, $fail);

        if (!IndexKey::wellFormed($key, $index)) {
            $fail(JsonProviderErrorEn::IndexKeyMalformed);
        }

        return ['key' => $key, 'line' => $line];
    }

    /**
     * The key and data line of one index line without its newline: a
     * {key, line} pair whose line lies inside the data file.
     *
     * @param \Closure(LocaleInterface, string...): never $fail
     *
     * @return array{string, int}
     */
    private static function fields(
        string $text,
        int $lineCount,
        \Closure $fail,
    ): array {
        $canonical = self::canonical($text);

        if ($canonical !== null) {
            if ($canonical[1] >= $lineCount) {
                $fail(JsonProviderErrorEn::IndexBrokenPermutation);
            }

            return $canonical;
        }

        $entry = json_decode($text, true);
        $key = \is_array($entry) ? ($entry['key'] ?? null) : null;
        $line = \is_array($entry) ? ($entry['line'] ?? null) : null;

        if (!\is_string($key) || !\is_int($line)) {
            $fail(JsonProviderErrorEn::IndexEntryMalformed);
        }

        if ($line < 0 || $line >= $lineCount) {
            $fail(JsonProviderErrorEn::IndexBrokenPermutation);
        }

        return [$key, $line];
    }

    /**
     * The lines of a run whose every entry is in the exact form the engine
     * writes (canonical()), matched in one pass over the text, with the
     * same checks the line-by-line reading makes: line references inside
     * the data file, keys in order. Null when an entry is in another form;
     * the run is then read line by line.
     *
     * @return null|list<int>
     */
    private function canonicalRun(string $raw): array | null
    {
        $rows = substr_count($raw, "\n");
        $matched = preg_match_all(
            '/^\{"key":"([0-9a-f]*)","line":(0|[1-9][0-9]{0,17})\}$/m',
            $raw,
            $match,
        );

        if ($matched !== $rows) {
            return null;
        }

        $keys = $match[1];
        $lines = array_map(intval(...), $match[2]);

        if ($lines !== [] && max($lines) >= $this->lineCount) {
            ($this->fail)(JsonProviderErrorEn::IndexBrokenPermutation);
        }

        $previous = '';

        foreach ($keys as $key) {
            if (strcmp($previous, $key) > 0) {
                ($this->fail)(JsonProviderErrorEn::IndexOrderBroken);
            }

            $previous = $key;
        }

        $this->keys += array_combine($lines, $keys);

        return $lines;
    }

    /**
     * The key and line of an entry in the exact form the engine writes —
     * {"key":"<lowercase hex>","line":<decimal>} — read without decoding
     * JSON; null for any other text, which is then decoded. A key without
     * escapes and a line without a sign, a leading zero or more than 18
     * digits read this way give what json_decode() gives.
     *
     * @return null|array{string, int}
     */
    private static function canonical(string $text): array | null
    {
        $length = \strlen($text);

        if (
            $length < 20
            || !str_starts_with($text, '{"key":"')
            || $text[$length - 1] !== '}'
        ) {
            return null;
        }

        $quote = strpos($text, '","line":', 8);

        if ($quote === false) {
            return null;
        }

        $key = substr($text, 8, $quote - 8);
        $digits = substr($text, $quote + 9, -1);

        if (
            strspn($key, '0123456789abcdef') !== \strlen($key)
            || $digits === ''
            || \strlen($digits) > 18
            || !ctype_digit($digits)
            || ($digits[0] === '0' && $digits !== '0')
        ) {
            return null;
        }

        return [$key, (int)$digits];
    }

    /**
     * The first line start whose key satisfies the monotone $matches (false
     * on a prefix of the sorted keys, true on the rest), or end() when no
     * key does.
     *
     * The search starts at $from, a line start the caller knows is at or
     * before the answer; when the entry there already matches, it is the
     * answer — consecutive lookups in key order cost one probe each.
     *
     * @param \Closure(string): bool $matches
     */
    private function firstWhere(\Closure $matches, int $from = 0): int
    {
        $lo = $from;
        $hi = $this->bytes;
        $floor = null;
        $ceiling = null;

        if ($lo < $hi && $matches($this->entryAt($lo)[0])) {
            return $lo;
        }

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

        if (!isset($this->starts[$offset])) {
            fseek($this->handle, $offset - 1);
            $rest = fgets($this->handle);
            $this->starts[$offset] = $rest === false
                ? PHP_INT_MAX
                : $offset - 1 + \strlen($rest);
        }

        return min($limit, $this->starts[$offset]);
    }

    /**
     * The key, data line and next position of the entry at a line start.
     * Probes are remembered: the bounds of one lookup (two per value of an
     * IN) pass through the same middle entries.
     *
     * @return array{string, int, int}
     */
    private function entryAt(int $position): array
    {
        if (isset($this->probes[$position])) {
            return $this->probes[$position];
        }

        fseek($this->handle, $position);
        $raw = fgets($this->handle);

        if ($raw === false || $position + \strlen($raw) > $this->bytes) {
            ($this->fail)(JsonProviderErrorEn::IndexEntryMalformed);
        }

        $entry = self::parse($raw, $this->index, $this->lineCount, $this->fail);

        return $this->probes[$position] = [
            $entry['key'],
            $entry['line'],
            $position + \strlen($raw),
        ];
    }
}
