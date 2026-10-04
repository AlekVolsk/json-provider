<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Bench;

use AV\JsonProvider\Storage\StorageManifest;
use AV\JsonProvider\Tests\Support\LoadFixture;
use Testo\Bench;

/**
 * What the storage format migration costs at the stress ceiling (TABLES
 * tables of ROWS rows). Migrating a 1.0 database rebuilds every index of every
 * table from its data, so the documentation (Versioned migrations) states its
 * cost as that of rebuildAllIndexes() over the whole database: TABLES times
 * LoadBenchWrite::rebuildIndexes.
 *
 * The suite runs as an ordered flow of one-shot steps, each measured once
 * against an empty `noop` — only the absolute time of "current" means
 * anything:
 *
 *   1. seedForMigration — bulk-seeds the database (generation 2);
 *   2. demoteToGeneration1 — turns it into what a 1.0 engine leaves: no
 *      manifest, no table stamps. The files are otherwise identical, which
 *      the compatibility tests establish;
 *   3. statusOfGeneration1 — storageStatus() over TABLES unstamped tables;
 *   4. migrateToGeneration2 — the migration itself, failing loudly unless
 *      it refreshed every table;
 *   5. statusAfterMigration — storageStatus() of the migrated database,
 *      failing loudly unless it is current;
 *   6. dropAfterMigration — tears the database down.
 *
 * The migration API exists from 1.1 on, so this suite does not run against
 * the 1.0 engine.
 */
final class LoadBenchMigration
{
    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function seedForMigration(): int
    {
        LoadFixture::seedFresh();

        return LoadFixture::TABLES * LoadFixture::ROWS;
    }

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function demoteToGeneration1(): int
    {
        $path = LoadFixture::dbPath() . '/meta.json';
        $meta = json_decode(
            (string)file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (!\is_array($meta)) {
            throw new \RuntimeException('meta.json is not an object');
        }

        foreach ($meta as $table => $entry) {
            if (\is_array($entry)) {
                unset($entry['dataIno']);
                $meta[$table] = $entry;
            }
        }

        file_put_contents(
            $path,
            json_encode($meta, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
        unlink(StorageManifest::path(LoadFixture::dbPath()));

        return \count($meta);
    }

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function statusOfGeneration1(): int
    {
        return \count(LoadFixture::db()->storageStatus()->pendingTables);
    }

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function migrateToGeneration2(): int
    {
        $report = LoadFixture::db()->migrateStorage();
        $refreshed = \count($report->refreshedTables);

        if ($report->steps !== ['1->2'] || $refreshed !== LoadFixture::TABLES) {
            throw new \RuntimeException(
                'the migration refreshed ' . $refreshed . ' tables',
            );
        }

        return $refreshed;
    }

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function statusAfterMigration(): int
    {
        if (!LoadFixture::db()->storageStatus()->isCurrent()) {
            throw new \RuntimeException('the migrated database is not current');
        }

        return LoadFixture::TABLES;
    }

    #[Bench(
        callables: ['noop' => [self::class, 'noop']],
        warmup: 0,
        calls: 1,
        iterations: 1,
        tolerance: INF,
    )]
    public static function dropAfterMigration(): int
    {
        LoadFixture::dropAndRestore();

        return 0;
    }

    /**
     * Empty comparison target for the one-shot steps; read the absolute
     * time of "current", not the ranking.
     */
    public static function noop(): int
    {
        return 0;
    }
}
