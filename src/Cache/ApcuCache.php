<?php

declare(strict_types=1);

namespace AV\JsonProvider\Cache;

use AV\JsonProvider\Exception\StorageException;
use Psr\Log\LoggerInterface;

/**
 * APCu cache adapter.
 * Requires the ext-apcu PHP extension — checked explicitly in the
 * constructor (unlike Redis/Memcached there is no typed carrier of the
 * extension, so the guard is reachable and stays).
 *
 * Fault tolerance (see the CacheInterface degradation policy): every
 * backend error degrades get() to a miss and the mutations to no-ops; a
 * cache outage never fails the database operation. Degradations are
 * reported to the optional PSR-3 logger at warning level.
 */
final class ApcuCache implements CacheInterface
{
    /**
     * @param int $ttl time-to-live in seconds (0 = no expiry)
     */
    public function __construct(
        private readonly int $ttl = 0,
        private readonly LoggerInterface | null $logger = null,
    ) {
        if (!\extension_loaded('apcu')) {
            throw StorageException::extensionRequired('apcu');
        }
    }

    public function get(string $key): array | null
    {
        try {
            $value = apcu_fetch($key);
        } catch (\Throwable $e) {
            $this->degraded('get', $e);

            return null;
        }

        if ($value === false || !\is_array($value)) {
            return null;
        }

        return self::sanitizeRows($value);
    }

    /**
     * @param array<int,array<string,null|scalar>> $records
     */
    public function set(string $key, array $records): void
    {
        try {
            apcu_store($key, $records, $this->ttl);
        } catch (\Throwable $e) {
            $this->degraded('set', $e);
        }
    }

    public function invalidate(string $key): void
    {
        try {
            apcu_delete($key);
        } catch (\Throwable $e) {
            $this->degraded('invalidate', $e);
        }
    }

    /**
     * Deletes only the keys under the given prefix via APCUIterator —
     * never apcu_clear_cache(): the shared APCu pool may hold other
     * databases and other applications.
     */
    public function flushDb(string $keyPrefix): void
    {
        try {
            $iterator = new \APCUIterator(
                '/^' . preg_quote($keyPrefix, '/') . '/',
            );
            apcu_delete($iterator);
        } catch (\Throwable $e) {
            $this->degraded('flushDb', $e);
        }
    }

    /**
     * Any structural anomaly in a stored payload degrades the WHOLE
     * entry to a miss — silently trimming the garbage would hand partial
     * rows to the query layer as if they were data.
     *
     * @param array<mixed> $value
     *
     * @return null|array<int,array<string,null|scalar>>
     */
    private static function sanitizeRows(array $value): array | null
    {
        $result = [];

        foreach ($value as $record) {
            if (!\is_array($record)) {
                return null;
            }

            $row = [];

            foreach ($record as $k => $v) {
                if (!\is_string($k) || (!\is_scalar($v) && $v !== null)) {
                    return null;
                }

                $row[$k] = $v;
            }

            $result[] = $row;
        }

        return $result;
    }

    private function degraded(string $operation, \Throwable $e): void
    {
        $this->logger?->warning(
            'APCu cache backend degraded on ' . $operation
                . ': ' . $e->getMessage(),
            ['exception' => $e],
        );
    }
}
