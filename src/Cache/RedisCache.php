<?php

declare(strict_types=1);

namespace AV\JsonProvider\Cache;

use AV\JsonProvider\Exception\StorageException;

/**
 * Redis cache adapter.
 * Requires the ext-redis PHP extension.
 */
final class RedisCache implements CacheInterface
{
    /**
     * @param int $ttl time-to-live in seconds (0 = no expiry)
     */
    public function __construct(
        private readonly \Redis $client,
        private readonly int $ttl = 0,
    ) {
        if (!\extension_loaded('redis')) {
            throw StorageException::extensionRequired('redis');
        }
    }

    public function get(string $key): array | null
    {
        $raw = $this->client->get($key);

        if ($raw === false || !\is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $result = [];

        foreach ($decoded as $record) {
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
     * JSON_PRESERVE_ZERO_FRACTION keeps float values (99.0) from collapsing
     * into ints across the encode/decode roundtrip, so a cache hit serves
     * the same PHP types as a cold disk read.
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    public function set(string $key, array $records): void
    {
        $json = json_encode($records, JSON_PRESERVE_ZERO_FRACTION);

        if ($json === false) {
            return;
        }

        if ($this->ttl > 0) {
            $this->client->setex($key, $this->ttl, $json);
        } else {
            $this->client->set($key, $json);
        }
    }

    public function invalidate(string $key): void
    {
        $this->client->del($key);
    }

    public function flush(): void
    {
        $this->client->flushDB();
    }
}
