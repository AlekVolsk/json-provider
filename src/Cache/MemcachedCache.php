<?php

declare(strict_types=1);

namespace AV\JsonProvider\Cache;

use Psr\Log\LoggerInterface;

/**
 * Memcached cache adapter.
 *
 * The ext-memcached requirement is carried by the \Memcached constructor
 * type hint itself — instantiation without the extension fails with a
 * TypeError before any explicit guard could run.
 *
 * Fault tolerance (see the CacheInterface degradation policy): every
 * backend error degrades get() to a miss and the mutations to no-ops; a
 * cache outage never fails the database operation. Degradations are
 * reported to the optional PSR-3 logger at warning level.
 *
 * flushDb: the memcached protocol cannot enumerate keys, so the adapter
 * keeps a GENERATION counter per key prefix (stored under
 * "<prefix>__gen") and splices the generation into every physical key.
 * flushDb increments the counter — all keys of the previous generation
 * become unreachable and expire by TTL/LRU. Two caveats (see the caching
 * doc): another worker sees the bump only when it re-instantiates the
 * adapter or its local generation memo is cold, and LRU eviction of the
 * counter key resets the generation — data correctness is carried by the
 * provider's version-tagged keys, not by flushDb.
 */
final class MemcachedCache implements CacheInterface
{
    private const string GEN_SUFFIX = '__gen';

    private readonly \Memcached $client;

    /** @var array<string,string> */
    private array $generationMemo = [];

    /**
     * @param int $ttl time-to-live in seconds (0 = no expiry)
     */
    public function __construct(
        \Memcached $client,
        private readonly int $ttl = 0,
        private readonly LoggerInterface | null $logger = null,
    ) {
        $this->client = $client;
    }

    public function get(string $key): array | null
    {
        try {
            $value = $this->client->get($this->physicalKey($key));

            if (
                $this->client->getResultCode() !== \Memcached::RES_SUCCESS
                || !\is_array($value)
            ) {
                return null;
            }
        } catch (\Throwable $e) {
            $this->degraded('get', $e);

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
            $this->client->set(
                $this->physicalKey($key),
                $records,
                $this->ttl,
            );
        } catch (\Throwable $e) {
            $this->degraded('set', $e);
        }
    }

    public function invalidate(string $key): void
    {
        try {
            $this->client->delete($this->physicalKey($key));
        } catch (\Throwable $e) {
            $this->degraded('invalidate', $e);
        }
    }

    /**
     * Bumps the generation of the prefix: the previous generation's keys
     * become unreachable and expire by TTL/LRU.
     */
    public function flushDb(string $keyPrefix): void
    {
        $counterKey = $keyPrefix . self::GEN_SUFFIX;

        try {
            $next = $this->client->increment($counterKey);

            if ($next === false) {
                $this->client->add($counterKey, 1, 0);
                $next = $this->client->increment($counterKey);

                if ($next === false) {
                    $next = 1;
                }
            }

            $this->generationMemo[$keyPrefix] = (string)$next;
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

    /**
     * Splices the current generation of the key's DATABASE namespace into
     * the physical key. The namespace is the provider's
     * "jdp:<version>:<db-hash>:" prefix — the same string flushDb
     * receives, so the generation counter bumped there is the one used
     * here. Keys outside that shape go through verbatim (and flushDb
     * cannot retire them). The generation is memoized per prefix for the
     * adapter's lifetime; flushDb refreshes the memo.
     */
    private function physicalKey(string $key): string
    {
        if (preg_match('/^(jdp:[^:]+:[^:]+:)(.+)$/', $key, $m) !== 1) {
            return $key;
        }

        [, $prefix, $rest] = $m;

        if (!isset($this->generationMemo[$prefix])) {
            $stored = $this->client->get($prefix . self::GEN_SUFFIX);
            $this->generationMemo[$prefix] = \is_int($stored)
                || (\is_string($stored) && $stored !== '')
                ? (string)$stored
                : '0';
        }

        return $prefix . 'g' . $this->generationMemo[$prefix] . ':' . $rest;
    }

    private function degraded(string $operation, \Throwable $e): void
    {
        $this->logger?->warning(
            'Memcached cache backend degraded on ' . $operation
                . ': ' . $e->getMessage(),
            ['exception' => $e],
        );
    }
}
