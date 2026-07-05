<?php

declare(strict_types=1);

namespace AV\JsonProvider\Cache;

use AV\JsonProvider\Exception\StorageException;

/**
 * Memcached cache adapter.
 * Requires the ext-memcached PHP extension.
 */
final class MemcachedCache implements CacheInterface
{
    private \Memcached $client;

    /**
     * @param int $ttl time-to-live in seconds (0 = no expiry)
     */
    public function __construct(
        \Memcached $client,
        private readonly int $ttl = 0,
    ) {
        if (!\extension_loaded('memcached')) {
            throw StorageException::extensionRequired('memcached');
        }

        $this->client = $client;
    }

    public function get(string $key): array | null
    {
        $value = $this->client->get($key);

        if (
            $this->client->getResultCode() !== \Memcached::RES_SUCCESS
            || !\is_array($value)
        ) {
            return null;
        }

        $result = [];

        foreach ($value as $record) {
            if (!\is_array($record)) {
                continue;
            }

            $row = [];

            foreach ($record as $k => $v) {
                if (\is_string($k) && (\is_scalar($v) || $v === null)) {
                    $row[$k] = $v;
                }
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param array<int,array<string,null|scalar>> $records
     */
    public function set(string $key, array $records): void
    {
        $this->client->set($key, $records, $this->ttl);
    }

    public function invalidate(string $key): void
    {
        $this->client->delete($key);
    }

    public function flush(): void
    {
        $this->client->flush();
    }
}
