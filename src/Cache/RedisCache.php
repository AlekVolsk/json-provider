<?php

declare(strict_types=1);

namespace AV\JsonProvider\Cache;

use Psr\Log\LoggerInterface;

/**
 * Redis cache adapter.
 *
 * The ext-redis requirement is carried by the \Redis constructor type
 * hint itself — instantiation without the extension fails with a
 * TypeError before any explicit guard could run.
 *
 * Fault tolerance (see the CacheInterface degradation policy): every
 * backend error degrades get() to a miss and the mutations to no-ops; a
 * cache outage never fails the database operation. Degradations are
 * reported to the optional PSR-3 logger at warning level.
 */
final class RedisCache implements CacheInterface
{
    private const int SCAN_BATCH = 1000;

    /**
     * @param int $ttl time-to-live in seconds (0 = no expiry)
     */
    public function __construct(
        private readonly \Redis $client,
        private readonly int $ttl = 0,
        private readonly LoggerInterface | null $logger = null,
    ) {
    }

    public function get(string $key): array | null
    {
        try {
            $raw = $this->client->get($key);
        } catch (\Throwable $e) {
            $this->degraded('get', $e);

            return null;
        }

        if ($raw === false || !\is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            return null;
        }

        return self::sanitizeRows($decoded);
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

        try {
            if ($this->ttl > 0) {
                $this->client->setex($key, $this->ttl, $json);
            } else {
                $this->client->set($key, $json);
            }
        } catch (\Throwable $e) {
            $this->degraded('set', $e);
        }
    }

    public function invalidate(string $key): void
    {
        try {
            $this->client->del($key);
        } catch (\Throwable $e) {
            $this->degraded('invalidate', $e);
        }
    }

    /**
     * Cursor-based SCAN over the prefix — never FLUSHDB: the backend may
     * be shared by other databases and other applications. unlink() (lazy
     * free) with a del() fallback for pre-4.0 servers.
     */
    public function flushDb(string $keyPrefix): void
    {
        try {
            $iterator = null;

            do {
                /** @var array<int,string>|false $keys */
                $keys = $this->client->scan(
                    $iterator,
                    $keyPrefix . '*',
                    self::SCAN_BATCH,
                );

                if (\is_array($keys) && $keys !== []) {
                    try {
                        $this->client->unlink($keys);
                    } catch (\Throwable) {
                        $this->client->del($keys);
                    }
                }
            } while ($iterator !== 0 && $iterator !== null);
        } catch (\Throwable $e) {
            $this->degraded('flushDb', $e);
        }
    }

    /**
     * Any structural anomaly in a stored payload (a non-array row, a
     * nested or non-scalar value, a non-string column key) degrades the
     * WHOLE entry to a miss — silently trimming the garbage would hand
     * partial rows to the query layer as if they were data.
     *
     * @param array<mixed> $decoded
     *
     * @return null|array<int,array<string,null|scalar>>
     */
    private static function sanitizeRows(array $decoded): array | null
    {
        $result = [];

        foreach ($decoded as $record) {
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
            'Redis cache backend degraded on ' . $operation
                . ': ' . $e->getMessage(),
            ['exception' => $e],
        );
    }
}
