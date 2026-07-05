<?php

declare(strict_types=1);

namespace AV\JsonProvider\Cache;

/**
 * In-process memory cache adapter.
 * Keeps table records inside PHP memory of the current process — perfect for
 * long-running workers (RoadRunner, Swoole): on the first read tables are
 * fetched from disk, all subsequent reads within the worker are served from
 * RAM. On write the provider invalidates the corresponding key, so data stays
 * consistent with the underlying file.
 *
 * For classic SAPI the cache is effectively per-request (process dies after
 * response), which is still safe and costs nothing.
 *
 * This adapter is NOT shared between processes. Multi-worker setups must either
 * accept that each worker holds its own copy (eventual consistency via
 * invalidate-on-write) or mount a shared cache adapter (Redis, Memcached).
 */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string, array<int, array<string, null|scalar>>> */
    private array $store = [];

    public function get(string $key): array | null
    {
        return $this->store[$key] ?? null;
    }

    /**
     * @param array<int, array<string, null|scalar>> $records
     */
    public function set(string $key, array $records): void
    {
        $this->store[$key] = $records;
    }

    public function invalidate(string $key): void
    {
        unset($this->store[$key]);
    }

    public function flush(): void
    {
        $this->store = [];
    }
}
