<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the UTF-8 string contract: any byte sequence that is not valid
 * UTF-8 (truncated multibyte, overlong encoding, lone surrogate) is
 * rejected with INVALID_UTF8 before any disk write, symmetrically on
 * insert and update; every well-formed UTF-8 string — ASCII, Cyrillic,
 * emoji, BOM, NUL bytes — passes.
 */
final class Utf8ValidationTest
{
    private const string DB_PATH = '/tmp/jp-utf8-tests';
    private const string TABLE = 'texts';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            uniqueConstraints: [],
            columns: [
                'id'  => 'int',
                's'   => 'string',
                'opt' => 'string|null',
            ],
            indexes: [],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    // -- rejection ---------------------------------------------------------

    #[Test]
    public function insertBrokenUtf8Rejected(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('not valid UTF-8');

        $this->db->insert(self::TABLE, ['s' => "\xB1\x31"]);
    }

    #[Test]
    public function insertTruncatedMultibyteRejected(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('not valid UTF-8');

        $this->db->insert(self::TABLE, ['s' => substr('Привет', 0, 3)]);
    }

    #[Test]
    public function insertOverlongEncodingRejected(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('not valid UTF-8');

        $this->db->insert(self::TABLE, ['s' => "\xC0\xAF"]);
    }

    #[Test]
    public function insertLoneSurrogateRejected(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('not valid UTF-8');

        $this->db->insert(self::TABLE, ['s' => "\xED\xA0\x80"]);
    }

    #[Test]
    public function brokenUtf8OnNullableColumnRejected(): void
    {
        Expect::exception(StorageException::class)
            ->withMessageContaining('not valid UTF-8');

        $this->db->insert(
            self::TABLE,
            ['s' => 'ok', 'opt' => "\xFF\xFE"],
        );
    }

    #[Test]
    public function errorKeyIsInvalidUtf8(): void
    {
        try {
            $this->db->insert(self::TABLE, ['s' => "\xB1\x31"]);
            Assert::fail('a StorageException was expected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_UTF8');
            Assert::string($e->getMessage())->contains(self::TABLE);
            Assert::string($e->getMessage())->contains('"s"');
        }
    }

    #[Test]
    public function updateBrokenUtf8RejectedBeforeAnyWrite(): void
    {
        $id = $this->db->insert(self::TABLE, ['s' => 'intact']);
        $before = md5((string)file_get_contents($this->dataPath()));

        try {
            $this->db->table(self::TABLE)
                ->where('id', '=', $id)
                ->updateByArray(['s' => "\xB1\x31"]);
            Assert::fail('a StorageException was expected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_UTF8');
        }

        $after = md5((string)file_get_contents($this->dataPath()));
        Assert::same($after, $before);
    }

    // -- valid strings pass ------------------------------------------------

    #[Test]
    public function wellFormedUtf8Passes(): void
    {
        $values = [
            'ascii',
            '',
            'Привет, мир',
            '🚀 déjà vu ñ',
            "\xEF\xBB\xBFbom-prefixed",
            "nul\x00byte",
        ];

        foreach ($values as $i => $value) {
            $id = $this->db->insert(self::TABLE, ['s' => $value]);

            $this->db->invalidateCache(self::TABLE);
            $row = $this->db->table(self::TABLE)
                ->where('id', '=', $id)->selectOneByArray();
            Assert::notNull($row);
            Assert::same($row['s'], $value, "roundtrip #{$i}");
        }
    }

    #[Test]
    public function nullOnNullableStringColumnPasses(): void
    {
        $id = $this->db->insert(self::TABLE, ['s' => 'x', 'opt' => null]);

        $row = $this->db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();

        Assert::notNull($row);
        Assert::null($row['opt']);
    }

    // -- helpers -----------------------------------------------------------

    private function dataPath(): string
    {
        return $this->dbDir . '/' . self::TABLE . '/' . self::TABLE
            . '.ndjson';
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
}
