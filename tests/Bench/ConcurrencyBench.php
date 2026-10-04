<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Bench;

use AV\JsonProvider\Tests\Support\ConcurrencyFixture;
use Testo\Bench;

/**
 * Lock contention between readers and a writer (see ConcurrencyFixture):
 * how long a write waits behind a stream of index reads, and how long an
 * index read waits behind a stream of writes.
 *
 * The suite runs as an ordered flow:
 *
 *   1. seedForConcurrency — seeds the busy and the quiet database;
 *   2. startPollers — POLLERS background processes poll the busy one;
 *   3. rootTouchUnderPolling — updating one root row against updating one
 *      row of a same-size leaf table, both under the polls: the gap is what
 *      the width of the root's lock plan costs;
 *   4. switchToWriter — stops the pollers, starts one writer process that
 *      touches the root of the busy database every WRITER_PAUSE_US;
 *   5. pollUnderRootTouches — one poll of the busy database against the
 *      same poll of the quiet one: the gap is how long reads wait for the
 *      root touches;
 *   6. dropAfterConcurrency — stops the writer and removes both databases.
 *
 * The steps are measured against an empty `noop`; only the pairs of 3 and
 * 5 mean anything beyond their absolute time. The workers compete for the
 * CPU with the measured process, so the absolute times depend on the
 * machine; the pairs share that load and stay comparable. This suite must
 * not run in parallel with another one.
 */
final class ConcurrencyBench
{
    private static int $touches = 0;

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function seedForConcurrency(): int
    {
        ConcurrencyFixture::seed();

        return 0;
    }

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function startPollers(): int
    {
        ConcurrencyFixture::start(
            ConcurrencyFixture::busyPath(),
            'poll',
            ConcurrencyFixture::POLLERS,
        );
        usleep(500_000);

        return 0;
    }

    #[Bench(
        callables: ['leaf' => [self::class, 'leafTouch']],
        warmup: 1,
        calls: 1,
        iterations: 20,
        tolerance: INF,
    )]
    public static function rootTouchUnderPolling(): int
    {
        ConcurrencyFixture::touchRoot(
            ConcurrencyFixture::busy(),
            self::next(),
        );

        return 0;
    }

    public static function leafTouch(): int
    {
        ConcurrencyFixture::touchLeaf(
            ConcurrencyFixture::busy(),
            self::next(),
        );

        return 0;
    }

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function switchToWriter(): int
    {
        ConcurrencyFixture::stop();
        ConcurrencyFixture::start(ConcurrencyFixture::busyPath(), 'write', 1);
        usleep(500_000);

        return 0;
    }

    #[Bench(
        callables: ['quiet' => [self::class, 'pollQuiet']],
        warmup: 1,
        calls: 3,
        iterations: 8,
        tolerance: INF,
    )]
    public static function pollUnderRootTouches(): int
    {
        return ConcurrencyFixture::poll(
            ConcurrencyFixture::busy(),
            self::next(),
        );
    }

    public static function pollQuiet(): int
    {
        return ConcurrencyFixture::poll(
            ConcurrencyFixture::quiet(),
            self::next(),
        );
    }

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function dropAfterConcurrency(): int
    {
        ConcurrencyFixture::stop();
        ConcurrencyFixture::drop();

        return 0;
    }

    public static function noop(): int
    {
        return 0;
    }

    /**
     * The next row to touch or container to poll, walking the table so no
     * two calls repeat each other.
     */
    private static function next(): int
    {
        self::$touches = self::$touches % ConcurrencyFixture::ROWS + 1;

        return self::$touches;
    }
}
