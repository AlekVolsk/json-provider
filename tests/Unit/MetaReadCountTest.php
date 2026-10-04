<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * How many times one operation reads meta.json — the file holding the
 * counters of every table, read and decoded whole each time.
 *
 * The trust gate of a query and of a write reads it once and judges the
 * table from that snapshot: the counters, the stamp and the index format.
 *
 * JsonStorage reads every JSON file with an unqualified file_get_contents,
 * so a function of that name declared in its namespace takes the call.
 * The declaration would shadow the function for the whole process, so the
 * operation runs in a child process that declares a counting one.
 */
final class MetaReadCountTest
{
    private const string TABLE = 'items';

    private const string CHILD = <<<'PHP'
        namespace AV\JsonProvider\Storage {
            function file_get_contents(string $path): string | false
            {
                if (basename($path) === 'meta.json') {
                    \AV\JsonProvider\Tests\Unit\MetaReadCountTest::$reads++;
                }
                return \file_get_contents($path);
            }
        }
        namespace {
            [, $autoload, $dbDir, $op] = $argv;
            require $autoload;
            exit(\AV\JsonProvider\Tests\Unit\MetaReadCountTest::child(
                $dbDir,
                $op,
            ));
        }
        PHP;

    public static int $reads = 0;

    private string $root;

    private string $dbDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-meta-read-count');
        $this->dbDir = $this->root . '/' . uniqid('db', true);

        $db = JsonDataProvider::createDatabase($this->dbDir);
        $db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: ['id' => ColumnTypes::INT, 'n' => ColumnTypes::INT],
            indexes: [new IndexSchema('idx_n', [
                new IndexFieldSchema('n', SortDirectionEnum::ASC),
            ])],
        ));
        $rows = [];

        for ($id = 1; $id <= 50; $id++) {
            $rows[] = ['id' => $id, 'n' => $id % 5];
        }

        $db->importRecords(self::TABLE, $rows);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * An indexed query reads meta.json once: the trust gate, whose
     * snapshot also gives the line count the lookup checks against.
     */
    #[Test]
    public function indexedSelectReadsMetaOnce(): void
    {
        Assert::same($this->reads('select'), 1);
    }

    /**
     * An insert reads meta.json six times: the trust gate, the id
     * watermark check, the id allocation, the line number of the new
     * line, the commit and the cache version tag.
     */
    #[Test]
    public function insertReadsMetaSixTimes(): void
    {
        Assert::same($this->reads('insert'), 6);
    }

    /**
     * An update reads meta.json four times: the trust gate, the cache
     * version tag, the commit and the index format check of the plan.
     */
    #[Test]
    public function updateReadsMetaFourTimes(): void
    {
        Assert::same($this->reads('update'), 4);
    }

    /**
     * Runs in the child: opens the database, warms it with one query, then
     * counts the reads of meta.json the operation makes and prints them.
     */
    public static function child(string $dbDir, string $op): int
    {
        $table = JsonDataProvider::getInstance($dbDir)->table(self::TABLE);
        $table->where('n', '=', 1)->selectAllByArray();
        self::$reads = 0;

        match ($op) {
            'select' => $table->where('n', '=', 3)->selectAllByArray(),
            'insert' => $table->insertByArray(['n' => 3]),
            'update' => $table->where('n', '=', 3)
                ->updateByArray(['n' => 4]),
            default => throw new \InvalidArgumentException(
                'unknown operation: ' . $op,
            ),
        };

        echo self::$reads;

        return 0;
    }

    private function reads(string $op): int
    {
        $process = proc_open([
            PHP_BINARY,
            '-r',
            self::CHILD,
            '--',
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $this->dbDir,
            $op,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        Assert::true(\is_resource($process), 'cannot start a child process');

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        Assert::same(proc_close($process), 0, 'child failed: ' . $stderr);
        Assert::true(ctype_digit($stdout), 'child printed: ' . $stdout);

        return (int)$stdout;
    }
}
