<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\InMemoryCache;
use Testo\Assert;
use Testo\Test;

/**
 * Tests for the InMemoryCache bounds: FIFO eviction over maxEntries,
 * lazy TTL expiry, prefix-scoped flushDb, and the compatible no-argument
 * constructor.
 */
final class InMemoryCacheTest
{
    #[Test]
    public function fifoEvictionDropsTheOldestEntries(): void
    {
        $cache = new InMemoryCache(ttl: 0, maxEntries: 3);

        foreach (['a', 'b', 'c', 'd'] as $i => $key) {
            $cache->set($key, [['id' => $i]]);
        }

        Assert::same($cache->get('a'), null, 'the oldest entry is evicted');
        Assert::notNull($cache->get('b'));
        Assert::notNull($cache->get('c'));
        Assert::notNull($cache->get('d'));
    }

    #[Test]
    public function rewritingAKeyRefreshesItsEvictionPosition(): void
    {
        $cache = new InMemoryCache(ttl: 0, maxEntries: 2);

        $cache->set('a', [['id' => 1]]);
        $cache->set('b', [['id' => 2]]);
        $cache->set('a', [['id' => 3]]);
        $cache->set('c', [['id' => 4]]);

        Assert::same(
            $cache->get('b'),
            null,
            'b became the oldest after a was re-set',
        );
        Assert::notNull($cache->get('a'));
        Assert::notNull($cache->get('c'));
    }

    #[Test]
    public function expiredEntriesVanishOnGet(): void
    {
        $cache = new InMemoryCache(ttl: 1);
        $cache->set('k', [['id' => 1]]);

        Assert::notNull($cache->get('k'));

        usleep(2_100_000);

        Assert::same($cache->get('k'), null, 'the entry expired by ttl');
    }

    #[Test]
    public function zeroLimitsKeepTheHistoricalUnboundedBehavior(): void
    {
        $cache = new InMemoryCache(ttl: 0, maxEntries: 0);

        for ($i = 0; $i < 1500; $i++) {
            $cache->set('k' . $i, [['id' => $i]]);
        }

        Assert::notNull($cache->get('k0'));
        Assert::notNull($cache->get('k1499'));
    }

    #[Test]
    public function noArgumentConstructorWorksWithinTheDefaultCap(): void
    {
        $cache = new InMemoryCache();
        $cache->set('k', [['id' => 1]]);

        Assert::notNull($cache->get('k'));
    }

    #[Test]
    public function flushDbRemovesOnlyThePrefixedKeys(): void
    {
        $cache = new InMemoryCache();
        $cache->set('jdp:2:aaaa:users:1-10', [['id' => 1]]);
        $cache->set('jdp:2:bbbb:users:1-10', [['id' => 2]]);
        $cache->set('unrelated', [['id' => 3]]);

        $cache->flushDb('jdp:2:aaaa:');

        Assert::same($cache->get('jdp:2:aaaa:users:1-10'), null);
        Assert::notNull($cache->get('jdp:2:bbbb:users:1-10'));
        Assert::notNull($cache->get('unrelated'));
    }
}
