<?php

declare(strict_types=1);

namespace AV\JsonProvider\Cache;

/**
 * Cache interface for the data provider.
 * Default implementation is NullCache (no caching); the bundled adapters
 * are ApcuCache, MemcachedCache, RedisCache and InMemoryCache. A custom
 * adapter can be injected without modifying the provider.
 *
 * Degradation policy — a contract EVERY adapter (including custom ones)
 * must honor: a cache backend failure never fails the database operation
 * and never serves wrong data. Any backend error degrades get() to null
 * (a miss — the provider falls back to disk) and turns the mutation
 * methods (set/invalidate/flushDb) into silent no-ops; adapters may log
 * the degradation but must not throw.
 */
interface CacheInterface
{
    /**
     * Returns cached records by key; null on cache miss.
     *
     * @return null|array<int,array<string,null|scalar>>
     */
    public function get(string $key): array | null;

    /**
     * Stores records in cache.
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    public function set(string $key, array $records): void;

    /**
     * Invalidates a single cache entry by key.
     */
    public function invalidate(string $key): void;

    /**
     * Removes ONLY the entries whose key starts with the given prefix —
     * never the whole backend. The provider passes its per-database
     * namespace, so flushing one database leaves the other tenants of a
     * shared pool untouched.
     */
    public function flushDb(string $keyPrefix): void;
}
