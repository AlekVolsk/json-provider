<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\JsonStorageTxHandle;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for atomic full-file replacement (tmp+fsync+rename) in NdjsonStorage
 * and JsonStorage: a failed write never corrupts the target, readers always
 * see a complete file, service-file writes and transactions exclude each
 * other across processes via the sidecar lock.
 */
final class StorageAtomicityTest
{
    private const string TABLE = 'items';
    private const string DATA_FILE = 'items.ndjson';

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::tmpDirRoot());
        mkdir(self::tmpDirRoot() . '/' . self::TABLE, 0755, true);
        touch(self::tmpDirRoot() . '/' . self::TABLE . '/' . self::DATA_FILE);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::tmpDirRoot());
    }

    #[Test]
    public function writeReplacesContentAndReturnsByteSize(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());

        $size = $storage->write(self::TABLE, self::DATA_FILE, [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        $path = $this->dataPath();
        Assert::same($size, filesize($path));

        $records = $storage->read(self::TABLE, self::DATA_FILE);
        Assert::count($records, 2);
        Assert::same($records[0], ['id' => 1, 'name' => 'a']);
        Assert::same($records[1], ['id' => 2, 'name' => 'b']);
    }

    #[Test]
    public function writePreservesFloatZeroFractionOnDisk(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());

        $storage->write(self::TABLE, self::DATA_FILE, [
            ['id' => 1, 'price' => 99.0],
        ]);

        $raw = file_get_contents($this->dataPath());
        Assert::string($raw)->contains('99.0');
    }

    #[Test]
    public function failedEncodeMidSetLeavesFileByteIdentical(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());
        $storage->write(self::TABLE, self::DATA_FILE, [
            ['id' => 1, 'name' => 'keep-me'],
            ['id' => 2, 'name' => 'me-too'],
        ]);
        $before = file_get_contents($this->dataPath());

        $caught = null;

        try {
            $storage->write(self::TABLE, self::DATA_FILE, [
                ['id' => 1, 'name' => 'ok'],
                ['id' => 2, 'name' => "\xC3\x28"],
                ['id' => 3, 'name' => 'never-written'],
            ]);
        } catch (JsonProviderException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getErrorKey(), 'RecordJsonEncodeFailed');
        Assert::same(file_get_contents($this->dataPath()), $before);
    }

    #[Test]
    public function writeLeavesNoTmpFileBehind(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());
        $storage->write(self::TABLE, self::DATA_FILE, [['id' => 1]]);

        Assert::same($this->tmpSiblings(), []);
    }

    #[Test]
    public function staleTmpFilesAreSweptByNextWrite(): void
    {
        file_put_contents($this->dataPath() . '.tmp', 'stale garbage');
        file_put_contents(
            $this->dataPath() . '.12345.aabbccdd.tmp',
            'crashed writer leftovers',
        );

        $storage = new NdjsonStorage(self::tmpDirRoot());
        $storage->write(self::TABLE, self::DATA_FILE, [['id' => 7]]);

        Assert::same($this->tmpSiblings(), []);

        $records = $storage->read(self::TABLE, self::DATA_FILE);
        Assert::count($records, 1);
        Assert::same($records[0]['id'], 7);
    }

    #[Test]
    public function writeOnMissingFileThrowsFileNotReadable(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());

        $caught = null;

        try {
            $storage->write(self::TABLE, 'absent.ndjson', [['id' => 1]]);
        } catch (JsonProviderException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getErrorKey(), 'FileNotReadable');
        Assert::false(
            file_exists(
                self::tmpDirRoot() . '/' . self::TABLE . '/absent.ndjson',
            ),
        );
    }

    #[Test]
    public function openReaderKeepsSeeingOldContentAfterRewrite(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());
        $storage->write(self::TABLE, self::DATA_FILE, [
            ['id' => 1, 'v' => 'old'],
            ['id' => 2, 'v' => 'old'],
        ]);

        $handle = fopen($this->dataPath(), 'r');
        \assert($handle !== false);
        $firstLine = fgets($handle);

        $storage->write(self::TABLE, self::DATA_FILE, [
            ['id' => 1, 'v' => 'new'],
        ]);

        $rest = stream_get_contents($handle);
        fclose($handle);

        Assert::string((string)$firstLine)->contains('old');
        Assert::string((string)$rest)->contains('old');
        Assert::false(str_contains((string)$rest, 'new'));

        $records = $storage->read(self::TABLE, self::DATA_FILE);
        Assert::count($records, 1);
        Assert::same($records[0]['v'], 'new');
    }

    #[Test]
    public function concurrentReadersAlwaysSeeACompleteFile(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());
        $recordCount = 50;

        $records = [];

        for ($i = 1; $i <= $recordCount; $i++) {
            $records[] = ['id' => $i, 'payload' => str_repeat('x', 100)];
        }

        $storage->write(self::TABLE, self::DATA_FILE, $records);

        $code = <<<'PHP'
            require $argv[1];
            $storage = new \AV\JsonProvider\Storage\NdjsonStorage($argv[2]);
            $records = [];
            for ($i = 1; $i <= (int)$argv[3]; $i++) {
                $records[] = ['id' => $i, 'payload' => str_repeat('x', 100)];
            }
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                $storage->write('items', 'items.ndjson', $records);
            }
            echo "done\n";
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            self::tmpDirRoot(),
            (string)$recordCount,
        ]);

        try {
            $reads = 0;
            $deadline = microtime(true) + 2.5;

            while (microtime(true) < $deadline) {
                $seen = $storage->read(self::TABLE, self::DATA_FILE);
                Assert::count($seen, $recordCount);
                $reads++;
            }

            Assert::int($reads)->greaterThan(0);
        } finally {
            $stdout = $this->drainAndClose($child);
        }

        Assert::string($stdout)->contains('done');
    }

    #[Test]
    public function concurrentAppendsFromTwoProcessesAllSurvive(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());
        $perProcess = 100;

        $code = <<<'PHP'
            require $argv[1];
            $storage = new \AV\JsonProvider\Storage\NdjsonStorage($argv[2]);
            echo "ready\n";
            fflush(STDOUT);
            for ($i = 0; $i < (int)$argv[3]; $i++) {
                $storage->append('items', 'items.ndjson', [
                    'src' => 'child',
                    'n'   => $i,
                ]);
            }
            echo "done\n";
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            self::tmpDirRoot(),
            (string)$perProcess,
        ]);

        $ready = fgets($child['pipes'][1]);
        Assert::string((string)$ready)->contains('ready');

        for ($i = 0; $i < $perProcess; $i++) {
            $storage->append(self::TABLE, self::DATA_FILE, [
                'src' => 'parent',
                'n'   => $i,
            ]);
        }

        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('done');

        $records = $storage->read(self::TABLE, self::DATA_FILE);
        Assert::count($records, $perProcess * 2);

        foreach (['child', 'parent'] as $src) {
            $ns = [];

            foreach ($records as $record) {
                if ($record['src'] === $src) {
                    $ns[] = $record['n'];
                }
            }

            sort($ns);
            Assert::same($ns, range(0, $perProcess - 1));
        }
    }

    #[Test]
    public function appendReturnsGrowingFileSize(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());

        $size1 = $storage->append(self::TABLE, self::DATA_FILE, ['id' => 1]);
        Assert::same($size1, filesize($this->dataPath()));

        clearstatcache();
        $size2 = $storage->append(self::TABLE, self::DATA_FILE, ['id' => 2]);
        clearstatcache();
        Assert::same($size2, filesize($this->dataPath()));
        Assert::int($size2)->greaterThan($size1);

        $records = $storage->read(self::TABLE, self::DATA_FILE);
        Assert::count($records, 2);
    }

    #[Test]
    public function appendRejectsUnencodableRecordWithoutTouchingFile(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());
        $storage->write(self::TABLE, self::DATA_FILE, [['id' => 1]]);
        $before = file_get_contents($this->dataPath());

        $caught = null;

        try {
            $storage->append(self::TABLE, self::DATA_FILE, [
                'id'   => 2,
                'name' => "\xC3\x28",
            ]);
        } catch (JsonProviderException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getErrorKey(), 'RecordJsonEncodeFailed');
        Assert::same(file_get_contents($this->dataPath()), $before);
    }

    #[Test]
    public function encodeRecordsProducesNewlineTerminatedLines(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());

        $bytes = $storage->encodeRecords(self::TABLE, [
            ['id' => 1],
            ['id' => 2, 'price' => 10.0],
        ]);

        Assert::same($bytes, "{\"id\":1}\n{\"id\":2,\"price\":10.0}\n");
    }

    #[Test]
    public function encodeRecordsOfEmptySetIsEmptyString(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());

        Assert::same($storage->encodeRecords(self::TABLE, []), '');
    }

    #[Test]
    public function encodeRecordsThrowsInvalidRecordOnBadUtf8(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('Invalid record');

        $storage->encodeRecords(self::TABLE, [['name' => "\xC3\x28"]]);
    }

    #[Test]
    public function writeRawReplacesAtomicallyAndReturnsSize(): void
    {
        $storage = new NdjsonStorage(self::tmpDirRoot());
        $contents = "{\"id\":1}\n{\"id\":2}\n";

        $size = $storage->writeRaw(self::TABLE, self::DATA_FILE, $contents);

        Assert::same($size, \strlen($contents));
        Assert::same(file_get_contents($this->dataPath()), $contents);
        Assert::false(file_exists($this->dataPath() . '.tmp'));
    }

    #[Test]
    public function jsonWriteIsAtomicAndLeavesNoTmp(): void
    {
        $storage = new JsonStorage(self::tmpDirRoot());
        $storage->createFile('config.json', ['a' => 1]);

        $storage->write('config.json', ['a' => 2, 'b' => [1, 2, 3]]);

        Assert::same(
            $storage->read('config.json'),
            ['a' => 2, 'b' => [1, 2, 3]],
        );
        Assert::false(file_exists(self::tmpDirRoot() . '/config.json.tmp'));
    }

    #[Test]
    public function transactionExceptionLeavesFileUnchanged(): void
    {
        $storage = new JsonStorage(self::tmpDirRoot());
        $storage->createFile('config.json', ['counter' => 5]);
        $before = file_get_contents(self::tmpDirRoot() . '/config.json');

        $caught = null;

        try {
            $storage->transaction(
                'config.json',
                static function (
                    array $data,
                    JsonStorageTxHandle $h,
                ): void {
                    $h->save(['counter' => 6]);

                    throw new \RuntimeException('mid-transaction failure');
                },
            );
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        Assert::same($caught->getMessage(), 'mid-transaction failure');
        Assert::same(
            file_get_contents(self::tmpDirRoot() . '/config.json'),
            $before,
        );
    }

    #[Test]
    public function transactionWithoutSaveLeavesFileUnchanged(): void
    {
        $storage = new JsonStorage(self::tmpDirRoot());
        $storage->createFile('config.json', ['counter' => 5]);
        $before = file_get_contents(self::tmpDirRoot() . '/config.json');

        $result = $storage->transaction(
            'config.json',
            static fn (array $data): mixed => $data['counter'],
        );

        Assert::same($result, 5);
        Assert::same(
            file_get_contents(self::tmpDirRoot() . '/config.json'),
            $before,
        );
    }

    #[Test]
    public function transactionPersistsSavedData(): void
    {
        $storage = new JsonStorage(self::tmpDirRoot());
        $storage->createFile('config.json', ['counter' => 5]);

        $storage->transaction(
            'config.json',
            static function (array $data, JsonStorageTxHandle $h): void {
                \assert(\is_int($data['counter']));
                $h->save(['counter' => $data['counter'] + 1]);
            },
        );

        Assert::same($storage->read('config.json'), ['counter' => 6]);
        Assert::false(file_exists(self::tmpDirRoot() . '/config.json.tmp'));
    }

    #[Test]
    public function createObjectFileWritesEmptyJsonObject(): void
    {
        $storage = new JsonStorage(self::tmpDirRoot());
        $storage->createObjectFile('meta.json');

        Assert::same(
            file_get_contents(self::tmpDirRoot() . '/meta.json'),
            "{}\n",
        );
    }

    #[Test]
    public function crossProcessTransactionsNeverLoseIncrements(): void
    {
        $storage = new JsonStorage(self::tmpDirRoot());
        $storage->createFile('counter.json', ['counter' => 0]);
        $increments = 100;

        $code = <<<'PHP'
            require $argv[1];
            $storage = new \AV\JsonProvider\Storage\JsonStorage($argv[2]);
            echo "ready\n";
            fflush(STDOUT);
            for ($i = 0; $i < (int)$argv[3]; $i++) {
                $storage->transaction(
                    'counter.json',
                    static function (array $data, $h): void {
                        $h->save(['counter' => $data['counter'] + 1]);
                    },
                );
            }
            echo "done\n";
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            self::tmpDirRoot(),
            (string)$increments,
        ]);

        $ready = fgets($child['pipes'][1]);
        Assert::string((string)$ready)->contains('ready');

        for ($i = 0; $i < $increments; $i++) {
            $storage->transaction(
                'counter.json',
                static function (array $data, JsonStorageTxHandle $h): void {
                    \assert(\is_int($data['counter']));
                    $h->save(['counter' => $data['counter'] + 1]);
                },
            );
        }

        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('done');

        Assert::same(
            $storage->read('counter.json'),
            ['counter' => $increments * 2],
        );
    }

    #[Test]
    public function updateWithUnencodableValueLeavesTableFullyIntact(): void
    {
        $dbDir = self::tmpDirRoot() . '/probe-' . uniqid();
        $db = \AV\JsonProvider\JsonDataProvider::createDatabase($dbDir);
        $db->createTable(\AV\JsonProvider\Schema\TableSchema::create(
            name: 'goods',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'price' => 'float'],
            indexes: [],
        ));
        $db->insert('goods', ['price' => 1.5]);
        $db->insert('goods', ['price' => 2.5]);

        $dataPath = $dbDir . '/goods/goods.ndjson';
        $metaPath = $dbDir . '/meta.json';

        $tampered = str_replace(
            '"price":1.5',
            '"price":1e999',
            (string)file_get_contents($dataPath),
        );
        file_put_contents($dataPath, $tampered);
        $metaBefore = file_get_contents($metaPath);

        $caught = null;

        try {
            $db->table('goods')
                ->where('id', '=', 2)
                ->updateByArray(['price' => 9.9]);
        } catch (JsonProviderException $e) {
            $caught = $e;
        }

        Assert::notNull($caught);
        Assert::same($caught->getErrorKey(), 'RecordJsonEncodeFailed');
        Assert::same(file_get_contents($dataPath), $tampered);
        Assert::same(file_get_contents($metaPath), $metaBefore);

        $rows = $db->table('goods')->selectAllByArray();
        Assert::count($rows, 2);
        Assert::true(is_infinite((float)$rows[0]['price']));
        Assert::same($rows[1]['price'], 2.5);

        $caughtInsert = null;

        try {
            $db->insert('goods', ['price' => 3.5]);
        } catch (JsonProviderException $e) {
            $caughtInsert = $e;
        }

        Assert::notNull($caughtInsert);
        Assert::same($caughtInsert->getErrorKey(), 'RecordJsonEncodeFailed');
    }

    private function dataPath(): string
    {
        return self::tmpDirRoot() . '/' . self::TABLE . '/' . self::DATA_FILE;
    }

    /**
     * Temp-file siblings of the data file left in the table directory.
     *
     * @return list<string>
     */
    private function tmpSiblings(): array
    {
        $entries = scandir(self::tmpDirRoot() . '/' . self::TABLE);
        \assert($entries !== false);

        return array_values(array_filter(
            $entries,
            static fn (string $e): bool => str_ends_with($e, '.tmp'),
        ));
    }

    /**
     * @param list<string> $args
     *
     * @return array{proc: resource, pipes: array<int,resource>}
     */
    private function spawnPhp(string $code, array $args): array
    {
        $cmd = array_merge([PHP_BINARY, '-r', $code, '--'], $args);
        $pipes = [];
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        \assert(\is_resource($proc));

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /**
     * Waits for the child to finish and returns its full stdout.
     *
     * @param array{proc: resource, pipes: array<int,resource>} $child
     */
    private function drainAndClose(array $child): string
    {
        fclose($child['pipes'][0]);
        $stdout = stream_get_contents($child['pipes'][1]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['proc']);

        return $stdout === false ? '' : $stdout;
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);

            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }

    private static function tmpDirRoot(): string
    {
        return TempDir::root('jp-atomicity-tests');
    }
}
