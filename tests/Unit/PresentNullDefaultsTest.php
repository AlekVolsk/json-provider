<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IssueCategory;
use AV\JsonProvider\Services\Integrity\IssueSeverity;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the present-null emitter (bi-present-null-emitter) and the
 * typed back-fill of missing columns (dv-normalize-defaults +
 * bi-repairer-column-defaults): a present null in a non-nullable column
 * is a report-only warning that no rewrite ever masks with a default; a
 * MISSING column is back-filled with the type's zero value on every
 * normalization path (update rewrite, repair, restore) — never with a
 * blind null; ghost keys of raw stored lines never leak into query
 * results.
 */
final class PresentNullDefaultsTest
{
    private const string DB_PATH = '/tmp/jp-presentnull-tests';
    private const string TABLE = 'items';

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
            columns: [
                'id' => 'int',
                'n'  => 'int',
                's'  => 'string',
                'b'  => 'bool',
                'f'  => 'float',
                'o'  => 'int|null',
            ],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    #[Test]
    public function presentNullInNonNullableColumnIsReported(): void
    {
        $this->insertRow(1);
        $this->rewriteLine(0, [
            'id' => 1, 'n' => null, 's' => 'x',
            'b'  => true, 'f' => 1.5, 'o' => null,
        ]);

        $report = $this->db->validateTable(self::TABLE);
        $found = $report->issuesByCategory(IssueCategory::PRESENT_NULL);

        Assert::count($found, 1, $report->format());
        Assert::same($found[0]->severity, IssueSeverity::WARNING);
        Assert::same($found[0]->context['column'] ?? '', 'n');
        Assert::same($found[0]->context['line'] ?? '', '0');
    }

    #[Test]
    public function nullInNullableColumnIsClean(): void
    {
        $this->insertRow(1);

        $report = $this->db->validateTable(self::TABLE);

        Assert::count(
            $report->issuesByCategory(IssueCategory::PRESENT_NULL),
            0,
            $report->format(),
        );
    }

    #[Test]
    public function missingKeyIsNotPresentNull(): void
    {
        $this->insertRow(1);
        $this->rewriteLine(0, [
            'id' => 1, 's' => 'x', 'b' => true, 'f' => 1.5, 'o' => null,
        ]);

        $report = $this->db->validateTable(self::TABLE);

        Assert::count(
            $report->issuesByCategory(IssueCategory::PRESENT_NULL),
            0,
            'an absent key is the back-fill case, not present_null: '
                . $report->format(),
        );
    }

    #[Test]
    public function presentNullSurvivesRepairVerbatim(): void
    {
        $this->insertRow(1);
        $this->rewriteLine(0, [
            'id' => 1, 'n' => null, 's' => 'x',
            'b'  => true, 'f' => 1.5, 'o' => null,
        ]);

        $report = $this->db->repairTable(self::TABLE);
        $found = $report->issuesByCategory(IssueCategory::PRESENT_NULL);

        Assert::count($found, 1, $report->format());
        Assert::false($found[0]->repaired);
        Assert::null($found[0]->repairError);

        $row = $this->db->table(self::TABLE)
            ->where('id', '=', 1)->selectOneByArray();
        \assert(\is_array($row));
        Assert::null(
            $row['n'],
            'repair must keep the present null visible, not mask it '
                . 'with the type default',
        );
    }

    #[Test]
    public function repairBackfillsMissingColumnsWithTypedDefaults(): void
    {
        $this->insertRow(1);
        $this->rewriteLine(0, ['id' => 1]);

        $this->db->repairTable(self::TABLE);

        $row = $this->db->table(self::TABLE)
            ->where('id', '=', 1)->selectOneByArray();
        \assert(\is_array($row));

        Assert::same($row['n'], 0);
        Assert::same($row['s'], '');
        Assert::same($row['b'], false);
        Assert::same($row['f'], 0.0);
        Assert::null($row['o']);
    }

    #[Test]
    public function updateBackfillsMissingColumnsWithTypedDefaults(): void
    {
        $this->insertRow(1);
        $this->rewriteLine(0, ['id' => 1, 's' => 'keep']);

        $updated = $this->db->table(self::TABLE)
            ->where('id', '=', 1)->updateByArray(['o' => 7]);
        Assert::true($updated);

        $row = $this->db->table(self::TABLE)
            ->where('id', '=', 1)->selectOneByArray();
        \assert(\is_array($row));

        Assert::same($row['s'], 'keep');
        Assert::same($row['o'], 7);
        Assert::same($row['n'], 0, 'missing int must become 0, not null');
        Assert::same($row['b'], false);
        Assert::same($row['f'], 0.0);
    }

    #[Test]
    public function updateKeepsPresentNullVerbatim(): void
    {
        $this->insertRow(1);
        $this->rewriteLine(0, [
            'id' => 1, 'n' => null, 's' => 'x',
            'b'  => true, 'f' => 1.5, 'o' => null,
        ]);

        $this->db->table(self::TABLE)
            ->where('id', '=', 1)->updateByArray(['s' => 'y']);

        $row = $this->db->table(self::TABLE)
            ->where('id', '=', 1)->selectOneByArray();
        \assert(\is_array($row));

        Assert::null(
            $row['n'],
            'a present null is a visible violation, not a back-fill case',
        );
    }

    #[Test]
    public function missingNonNullableTemporalFailsLoudly(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'events',
            columns: [
                'id' => 'int',
                'at' => 'datetime',
                'v'  => 'string',
            ],
        ));
        $this->db->insert('events', [
            'at' => '2026-01-01 10:00:00',
            'v'  => 'x',
        ]);

        $path = $this->dbDir . '/events/events.ndjson';
        file_put_contents($path, '{"id":1,"v":"x"}' . "\n");

        try {
            $this->db->table('events')
                ->where('id', '=', 1)->updateByArray(['v' => 'y']);
            Assert::fail(
                'a missing non-nullable temporal has no safe default '
                    . 'and must fail loudly',
            );
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_RECORD');
            Assert::string($e->getMessage())->contains('no safe default');
        }
    }

    #[Test]
    public function restoreBackfillsMissingColumnsWithTypedDefaults(): void
    {
        $this->insertRow(1);
        $archive = $this->db->backup(self::DB_PATH . '/backfill.tar.gz');

        $manifestRaw = file_get_contents(
            'phar://' . $archive . '/manifest.json',
        );
        $manifest = json_decode((string)$manifestRaw, true);
        \assert(\is_array($manifest));
        unset($manifest['counters'], $manifest['checksums']);

        $phar = new \PharData($archive);
        $phar->addFromString(
            'manifest.json',
            (string)json_encode($manifest, JSON_PRETTY_PRINT),
        );
        $phar->addFromString(
            'tables/' . self::TABLE . '.ndjson',
            '{"id":1}' . "\n",
        );
        unset($phar);

        $this->db->restore($archive);

        $row = $this->db->table(self::TABLE)
            ->where('id', '=', 1)->selectOneByArray();
        \assert(\is_array($row));

        Assert::same($row['n'], 0);
        Assert::same($row['s'], '');
        Assert::same($row['b'], false);
        Assert::same($row['f'], 0.0);
        Assert::null($row['o']);
    }

    #[Test]
    public function ghostKeysDoNotLeakIntoReads(): void
    {
        $this->insertRow(1);
        $this->rewriteLine(0, [
            'id' => 1, 'n' => 5, 's' => 'x', 'b' => true,
            'f'  => 1.5, 'o' => null, 'ghost' => 'boo',
        ]);

        $rows = $this->db->table(self::TABLE)->selectAllByArray();
        Assert::count($rows, 1);
        Assert::false(
            \array_key_exists('ghost', $rows[0]),
            'select must project records onto the schema columns',
        );

        $all = $this->db->readAll(self::TABLE);
        Assert::count($all, 1);
        Assert::false(
            \array_key_exists('ghost', $all[0]),
            'readAll must project records onto the schema columns',
        );
    }

    private function insertRow(int $n): void
    {
        $this->db->insert(self::TABLE, [
            'n' => $n,
            's' => 'x',
            'b' => true,
            'f' => 1.5,
            'o' => null,
        ]);
    }

    /**
     * Replaces one stored line (0-based) with a hand-crafted JSON object —
     * the foreign-edit vector every finding here models.
     *
     * @param array<string,null|scalar> $row
     */
    private function rewriteLine(int $line, array $row): void
    {
        $path = $this->dbDir . '/' . self::TABLE . '/' . self::TABLE
            . '.ndjson';
        $lines = explode("\n", trim((string)file_get_contents($path)));
        $lines[$line] = (string)json_encode($row);
        file_put_contents($path, implode("\n", $lines) . "\n");
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
