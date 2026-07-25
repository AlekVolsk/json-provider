<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Two-process tests for the backup/restore locking contract
 * (bi-backup-consistent-snapshot, bi-restore-counters-and-locks):
 *
 *  - export runs under the database EX lock, so a multi-table FK cascade
 *    in another process can never be captured half-applied — an archive
 *    either holds the parent with all its children or neither;
 *  - restore serializes against concurrent writers: inserts land either
 *    before the snapshot or after the restore, the final state validates
 *    clean and the id sequence never re-mints a stored id.
 */
final class BackupConcurrencyTest
{
    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: 'prnts',
            columns: ['id' => 'int', 'name' => 'string'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'kids',
            columns: [
                'id'        => 'int',
                'parent_id' => 'int|null',
                'label'     => 'string',
            ],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'kids',
            foreignKey: 'parent_id',
            toTable: 'prnts',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function exportCapturesOneGenerationUnderCascadingWriter(): void
    {
        $writerCode = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            echo "ready\n";

            for ($i = 0; $i < 40; $i++) {
                $pid = $db->insert('prnts', ['name' => 'p' . $i]);

                for ($j = 0; $j < 3; $j++) {
                    $db->insert('kids', [
                        'parent_id' => $pid,
                        'label'     => 'k' . $i . '-' . $j,
                    ]);
                }

                $db->table('prnts')->deleteById($pid);
            }

            echo "done\n";
            PHP;

        $child = $this->spawnPhp($writerCode, [
            \dirname(__DIR__, 2),
            $this->dbDir,
        ]);

        $archives = [];

        for ($i = 0; $i < 4; $i++) {
            $archives[] = $this->db->backup(
                self::dbPathRoot() . '/gen-' . $i . '.tar.gz',
            );
            usleep(20_000);
        }

        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('done');

        foreach ($archives as $archive) {
            $parents = $this->memberIds($archive, 'prnts', 'id');
            $parentSet = array_flip($parents);
            $danglingRefs = [];

            foreach (
                $this->memberIds($archive, 'kids', 'parent_id') as $ref
            ) {
                if (!isset($parentSet[$ref])) {
                    $danglingRefs[] = $ref;
                }
            }

            Assert::same(
                $danglingRefs,
                [],
                'archive ' . basename($archive) . ' captured a cascade '
                    . 'half-applied: kids reference parents the archive '
                    . 'does not hold',
            );
        }
    }

    #[Test]
    public function restoreSerializesWithConcurrentInserts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->db->insert('prnts', ['name' => 'seed' . $i]);
        }

        $archive = $this->db->backup(self::dbPathRoot() . '/serialize.tar.gz');

        $writerCode = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $db = \AV\JsonProvider\JsonDataProvider::getInstance($argv[2]);
            echo "ready\n";
            $ok = 0;

            for ($i = 0; $i < 30; $i++) {
                $db->insert('prnts', ['name' => 'conc' . $i]);
                $ok++;
                usleep(2000);
            }

            echo "inserted:" . $ok . "\n";
            PHP;

        $child = $this->spawnPhp($writerCode, [
            \dirname(__DIR__, 2),
            $this->dbDir,
        ]);

        usleep(15_000);
        $this->db->restore($archive);

        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('inserted:30');

        $report = $this->db->validate();
        Assert::false($report->hasErrors(), $report->format());

        $ids = [];
        $maxId = 0;

        foreach ($this->db->table('prnts')->selectAllByArray() as $row) {
            $id = $row['id'];
            \assert(\is_int($id));
            Assert::false(
                isset($ids[$id]),
                'duplicate primary key ' . $id . ' after the race',
            );
            $ids[$id] = true;
            $maxId = max($maxId, $id);
        }

        $nextId = $this->db->insert('prnts', ['name' => 'after']);
        Assert::true(
            $nextId > $maxId,
            'the id sequence must stay above every stored id',
        );
    }

    /**
     * Reads the int values of one column from a table member of the
     * archive.
     *
     * @return list<int>
     */
    private function memberIds(
        string $archive,
        string $table,
        string $column,
    ): array {
        $raw = file_get_contents(
            'phar://' . $archive . '/tables/' . $table . '.ndjson',
        );
        \assert(\is_string($raw));

        $values = [];

        foreach (explode("\n", trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            \assert(\is_array($decoded));

            if (\is_int($decoded[$column] ?? null)) {
                $values[] = $decoded[$column];
            }
        }

        return $values;
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

        $ready = fgets($pipes[1]);
        \assert(\is_string($ready) && trim($ready) === 'ready');

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /**
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

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-backup-conc-tests');
    }
}
