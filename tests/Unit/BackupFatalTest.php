<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\FsList;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Temporary files of backup/restore after a PHP fatal error, which no
 * try/finally survives: the shutdown handler removes every registered
 * temporary file, except the safety snapshot of an interrupted restore —
 * the only copy of the state before it — whose path is reported instead.
 * Each scenario runs in a child PHP process that dies of memory exhaustion.
 */
final class BackupFatalTest
{
    private string $root = '';

    private string | null $snapshotDir = null;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = self::tmpRoot() . '/' . uniqid('run', true);
        mkdir($this->root, 0755, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove(self::tmpRoot());

        if ($this->snapshotDir !== null) {
            TempDir::remove($this->snapshotDir);
        }
    }

    #[Test]
    public function trackedTemporaryFileIsRemovedAfterFatalError(): void
    {
        $tracked = $this->root . '/.jp-backup-test.tar';
        $foreign = $this->root . '/kept.txt';

        $exit = $this->runChild(
            '16M',
            <<<'PHP'
                touch($argv[1]);
                touch($argv[2]);
                \AV\JsonProvider\Services\Backup\PharArchive::track($argv[1]);
                $hog = [];
                while (true) {
                    $hog[] = str_repeat('x', 1 << 20);
                }
                PHP,
            [$tracked, $foreign],
        );

        Assert::true($exit !== 0);
        Assert::false(file_exists($tracked));
        Assert::true(file_exists($foreign));
    }

    #[Test]
    public function restoreInterruptedByFatalKeepsSnapshotAndReportsIt(): void
    {
        $dbPath = $this->root . '/db';
        $db = JsonDataProvider::createDatabase($dbPath);

        foreach (['a', 'b'] as $table) {
            $db->createTable(TableSchema::create(
                name: $table,
                columns: ['v' => 'string'],
            ));
        }

        $db->insert('a', ['v' => 'from-archive']);
        $rows = [];

        for ($i = 1; $i <= 150000; $i++) {
            $rows[] = ['id' => $i, 'v' => str_repeat('x', 40)];
        }

        $db->importRecords('b', $rows);
        unset($rows);
        $archive = $db->backup($this->root . '/big.tar.gz');
        $db->truncate('a');
        $db->truncate('b');
        $db->insert('a', ['v' => 'current']);

        $copiesBefore = FsList::glob(sys_get_temp_dir() . '/jp-restore-*');
        $errorLog = $this->root . '/error.log';

        $exit = $this->runChild(
            '40M',
            <<<'PHP'
                ini_set('log_errors', '1');
                ini_set('display_errors', '0');
                ini_set('error_log', $argv[3]);
                \AV\JsonProvider\JsonDataProvider::getInstance($argv[1])
                    ->restore($argv[2]);
                PHP,
            [$dbPath, $archive, $errorLog],
        );

        Assert::true($exit !== 0);
        $log = (string)file_get_contents($errorLog);
        Assert::string($log)->contains('Allowed memory size');
        Assert::true(
            preg_match('~kept in (\S+/snapshot\.tar\.gz)~', $log, $m) === 1,
        );
        $snapshot = $m[1];
        $this->snapshotDir = \dirname($snapshot);
        Assert::true(is_file($snapshot));
        Assert::same(fileperms(\dirname($snapshot)) & 0o777, 0o700);
        Assert::same(
            FsList::glob(sys_get_temp_dir() . '/jp-restore-*'),
            $copiesBefore,
        );

        $recovered = JsonDataProvider::getInstance($dbPath);
        $recovered->restore($snapshot);
        Assert::same($recovered->table('a')->selectColumn('v'), ['current']);
        Assert::same($recovered->table('b')->count(), 0);
    }

    /**
     * Runs $body in a fresh PHP process with the given memory limit and the
     * project autoloader; $args arrive as $argv[1..].
     *
     * @param list<string> $args
     */
    private function runChild(string $memory, string $body, array $args): int
    {
        $script = $this->root . '/child-' . uniqid() . '.php';
        file_put_contents(
            $script,
            "<?php\nrequire " . var_export(
                \dirname(__DIR__, 2) . '/vendor/autoload.php',
                true,
            ) . ";\n" . $body . "\n",
        );

        $command = array_merge(
            [PHP_BINARY, '-d', 'memory_limit=' . $memory, $script],
            $args,
        );
        $process = proc_open(
            $command,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        Assert::true(\is_resource($process));

        return proc_close($process);
    }

    private static function tmpRoot(): string
    {
        return TempDir::root('jp-backup-fatal-tests');
    }
}
