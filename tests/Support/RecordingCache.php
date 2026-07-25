<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use AV\JsonProvider\Cache\CacheInterface;

/**
 * Cache decorator counting get() calls — proves write paths never consult
 * the cache as a data source.
 */
final class RecordingCache implements CacheInterface
{
    public int $getCalls = 0;

    public function __construct(
        private readonly CacheInterface $inner,
    ) {
    }

    public function get(string $key): array | null
    {
        $this->getCalls++;

        return $this->inner->get($key);
    }

    public function set(string $key, array $records): void
    {
        $this->inner->set($key, $records);
    }

    public function invalidate(string $key): void
    {
        $this->inner->invalidate($key);
    }

    public function flushDb(string $keyPrefix): void
    {
        $this->inner->flushDb($keyPrefix);
    }
}
