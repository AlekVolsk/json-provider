<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Backup\PharArchive;
use AV\JsonProvider\Storage\AtomicFileWriter;
use AV\JsonProvider\Tests\Support\FsList;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Expected failure paths are handled by checking conditions, not by
 * suppressing diagnostics: a lock file unlinked under a cached handle, a
 * temp file already gone, a missing directory — none of them may raise a
 * PHP warning, which an application converting warnings into exceptions
 * would turn into a failure. Every test runs with such a converting error
 * handler installed.
 */
final class QuietFailurePathsTest
{
    private string $dir = '';

    #[BeforeTest]
    public function setUp(): void
    {
        $this->dir = self::root() . '/' . uniqid('run', true);
        mkdir($this->dir, 0755, true);
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });
    }

    #[AfterTest]
    public function tearDown(): void
    {
        restore_error_handler();
        TempDir::remove(self::root());
    }

    #[Test]
    public function lockFileRemovedUnderCachedHandleRaisesNoWarning(): void
    {
        $db = JsonDataProvider::createDatabase($this->dir . '/db');
        $db->createTable(TableSchema::create(
            name: 't',
            columns: ['x' => 'int'],
        ));
        $db->insert('t', ['x' => 1]);

        unlink($this->dir . '/db/.locks/table.t.lock');
        $db->insert('t', ['x' => 2]);

        Assert::same($db->table('t')->count(), 2);
    }

    #[Test]
    public function missingFilesAndDirectoriesRaiseNoWarning(): void
    {
        $missing = $this->dir . '/missing';

        AtomicFileWriter::abort($missing . '.tmp');
        AtomicFileWriter::syncDirectory($missing);
        PharArchive::discard($missing . '.tar.gz');

        Assert::false(file_exists($missing));
    }

    #[Test]
    public function atomicWriteSweepsOrphansAndReplacesTheTarget(): void
    {
        $target = $this->dir . '/data.json';
        file_put_contents($target . '.123.abcd.tmp', 'orphan');

        AtomicFileWriter::write($target, 'fresh');

        Assert::same(file_get_contents($target), 'fresh');
        Assert::same(FsList::glob($this->dir . '/*.tmp'), []);
    }

    private static function root(): string
    {
        return TempDir::root('jp-quiet-failure-tests');
    }
}
