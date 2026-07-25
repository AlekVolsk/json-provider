<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

/**
 * Per-run temporary directory roots for the test suite.
 *
 * testo runs a suite in a single sequential process and exposes no per-run
 * session token (unlike ParaTest's TEST_TOKEN), so tests that clean a fixed
 * `/tmp/...` root in setUp/tearDown would wipe a concurrently running suite's
 * data out from under it. token() mints one random 8-character id per process
 * and root() folds it into the directory name, so two overlapping runs (a local
 * run beside CI, two invocations, a re-run over a still-finishing one) never
 * share — or delete — each other's trees.
 *
 * root() also schedules a shutdown-time cleanup of the directory it returns, so
 * a run-unique root is removed when the process ends even if a test keeps a
 * long-lived (singleton) database and never tears it down itself. That both
 * fixes the historical leftover-directory leak and stops each run's unique root
 * from accumulating under the temp dir.
 */
final class TempDir
{
    private static string | null $token = null;

    /** @var array<string,true> */
    private static array $scheduled = [];

    /**
     * A random 8-character id, stable for the lifetime of the process: the same
     * value for every call within one test run, a fresh value for the next run.
     */
    public static function token(): string
    {
        return self::$token ??= substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * A run-unique directory under the system temp dir: "<prefix>-<token>".
     * Its removal at process shutdown is scheduled once per distinct path.
     */
    public static function root(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . self::token();

        if (!isset(self::$scheduled[$dir])) {
            self::$scheduled[$dir] = true;
            register_shutdown_function(
                static fn (): null => self::remove($dir),
            );
        }

        return $dir;
    }

    /**
     * Recursively removes a directory if it still exists. Safe to call when the
     * path is already gone (a test's own tearDown ran first).
     */
    public static function remove(string $dir): null
    {
        if (!is_dir($dir)) {
            return null;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($dir);

        return null;
    }
}
