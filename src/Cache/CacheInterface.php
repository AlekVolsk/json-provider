<?php

declare(strict_types=1);

namespace AV\JsonProvider\Cache;

/**
 * Cache interface for the data provider.
 * Default implementation is NullCache (no caching).
 * Custom adapters (APCu, Redis, file) can be injected without
 * modifying the provider.
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
     * Flushes the entire cache.
     */
    public function flush(): void;
}
