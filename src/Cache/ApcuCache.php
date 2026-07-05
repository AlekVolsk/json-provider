<?php

declare(strict_types=1);

namespace AV\JsonProvider\Cache;

use AV\JsonProvider\Exception\StorageException;

/**
 * APCu cache adapter.
 * Requires the ext-apcu PHP extension.
 */
final class ApcuCache implements CacheInterface
{
    /**
     * @param int $ttl time-to-live in seconds (0 = no expiry)
     */
    public function __construct(
        private readonly int $ttl = 0,
    ) {
        if (!\extension_loaded('apcu')) {
            throw StorageException::extensionRequired('apcu');
        }
    }

    public function get(string $key): array | null
    {
        $value = apcu_fetch($key);

        if ($value === false || !\is_array($value)) {
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
        apcu_store($key, $records, $this->ttl);
    }

    public function invalidate(string $key): void
    {
        apcu_delete($key);
    }

    public function flush(): void
    {
        apcu_clear_cache();
    }
}
