<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for LIKE semantics: bytewise and case-sensitive matching where an
 * unescaped `%` is the only wildcard, a backslash escapes `%` and itself,
 * and `_` is a literal underscore (no SQL single-char wildcard). The
 * pattern must be a string and the column string/temporal/passthrough.
 */
final class LikeSemanticsTest
{
    private const string DB_PATH = '/tmp/jp-like-tests';
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
            columns: ['id' => 'int', 't' => 'string'],
            indexes: [],
        ));

        foreach (
            [
                '100% off',
                '1000 units',
                'Москва',
                'back\slash',
                'under_score',
                'underXscore',
            ] as $t
        ) {
            $this->db->insert(self::TABLE, ['t' => $t]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    #[Test]
    public function escapedPercentMatchesLiteralPercent(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('t', 'LIKE', '%100\%%')->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['t'], '100% off');
    }

    #[Test]
    public function containsLiteralPercentPattern(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('t', 'LIKE', '%\%%')->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['t'], '100% off');
    }

    #[Test]
    public function unescapedPercentIsWildcard(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('t', 'LIKE', '100%')->selectAllByArray();

        Assert::count($rows, 2);
    }

    #[Test]
    public function escapedBackslashMatchesLiteralBackslash(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('t', 'LIKE', '%back\\\slash%')->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['t'], 'back\slash');
    }

    #[Test]
    public function likeIsCaseSensitive(): void
    {
        Assert::count(
            $this->db->table(self::TABLE)
                ->where('t', 'LIKE', '%москва%')->selectAllByArray(),
            0,
        );
        Assert::count(
            $this->db->table(self::TABLE)
                ->where('t', 'LIKE', '%Москва%')->selectAllByArray(),
            1,
        );
    }

    #[Test]
    public function underscoreIsLiteralNotWildcard(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('t', 'LIKE', 'under_score')->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['t'], 'under_score');
    }

    #[Test]
    public function nonStringPatternRejected(): void
    {
        try {
            $this->db->table(self::TABLE)
                ->where('t', 'LIKE', 5)->selectAllByArray();
            Assert::fail('a non-string LIKE pattern must be rejected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'CONDITION_TYPE_MISMATCH');
        }
    }

    #[Test]
    public function notLikeInvertsCleanly(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('t', 'LIKE', '%score', not: true)->selectAllByArray();

        Assert::count($rows, 4);
    }

    #[Test]
    public function pcreFailureIsLoudNotAFalseMismatch(): void
    {
        $this->db->insert(
            self::TABLE,
            ['t' => str_repeat('a', 100000) . 'b'],
        );

        $pattern = str_repeat('a%', 40) . 'b';

        foreach ([false, true] as $not) {
            try {
                $this->db->table(self::TABLE)
                    ->where('t', 'LIKE', $pattern, not: $not)
                    ->selectAllByArray();
                Assert::fail('a PCRE failure must not read as a mismatch');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'LIKE_EVALUATION_FAILED');
            }
        }
    }

    #[Test]
    public function consecutivePercentsCollapse(): void
    {
        $rows = $this->db->table(self::TABLE)
            ->where('t', 'LIKE', '%%%100\%%%')->selectAllByArray();

        Assert::count($rows, 1);
        Assert::same($rows[0]['t'], '100% off');
    }

    // -- helpers -----------------------------------------------------------

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
