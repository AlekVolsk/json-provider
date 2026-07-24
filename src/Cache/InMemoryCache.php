<?php

declare(strict_types=1);

namespace AV\JsonProvider\Cache;

/**
 * In-process memory cache adapter.
 * Keeps table records inside PHP memory of the current process — suited
 * for long-running workers (RoadRunner, Swoole): on the first read tables
 * are fetched from disk, subsequent reads within the worker are served
 * from RAM. Cross-process coherence is NOT this adapter's job — the
 * provider's version-tagged keys make a stale entry unreachable after the
 * underlying file changes.
 *
 * For classic SAPI the cache is effectively per-request (process dies
 * after response), which is still safe and costs nothing.
 *
 * Bounded: $maxEntries caps the store with FIFO eviction in insertion
 * order (0 = unlimited), $ttl expires entries lazily on get (0 = keep
 * forever). The no-argument constructor keeps the historical default of
 * an unlimited-lifetime store, now capped at 1000 entries.
 */
final class InMemoryCache implements CacheInterface
{
    /**
     * @var array<string,array{exp:int,rows:array<int,array<string,
     *      null|scalar>>}>
     */
    private array $store = [];

    public function __construct(
        private readonly int $ttl = 0,
        private readonly int $maxEntries = 1000,
    ) {}

    public function get(string $key): array | null
    {
        $entry = $this->store[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['exp'] !== 0 && $entry['exp'] < time()) {
            unset($this->store[$key]);

            return null;
        }

        return $entry['rows'];
    }

    /**
     * @param array<int, array<string, null|scalar>> $records
     */
    public function set(string $key, array $records): void
    {
        unset($this->store[$key]);
        $this->store[$key] = [
            'exp'  => $this->ttl > 0 ? time() + $this->ttl : 0,
            'rows' => $records,
        ];

        while (
            $this->maxEntries > 0
            && \count($this->store) > $this->maxEntries
        ) {
            $oldest = array_key_first($this->store);
            unset($this->store[$oldest]);
        }
    }

    public function invalidate(string $key): void
    {
        unset($this->store[$key]);
    }

    public function flushDb(string $keyPrefix): void
    {
        foreach (array_keys($this->store) as $key) {
            if (str_starts_with($key, $keyPrefix)) {
                unset($this->store[$key]);
            }
        }
    }
}
