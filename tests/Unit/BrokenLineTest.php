<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IssueCategoryEnum;
use AV\JsonProvider\Services\Integrity\IssueSeverityEnum;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for broken NDJSON line detection (bi-broken-line-detect):
 * readRawLines separates records from unparseable lines with physical
 * line numbers; the validator reports each broken line as a report-only
 * broken_record finding; repair never quarantines or deletes them; the
 * optimize/key-order rewrites refuse to run over a file holding any
 * (the read+write roundtrip would silently drop them).
 */
final class BrokenLineTest
{
    private const string TABLE = 'items';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: ['id' => 'int', 'v' => 'string'],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function readRawLinesSeparatesRecordsFromBrokenLines(): void
    {
        file_put_contents(
            $this->dataPath(),
            '{"id":1,"v":"a"}' . "\n"
                . '{broken json' . "\n"
                . "\n"
                . '"just a scalar"' . "\n"
                . '{}' . "\n"
                . '{"id":2,"v":"b"}' . "\n",
        );

        $storage = new NdjsonStorage($this->dbDir);
        $result = $storage->readRawLines(
            self::TABLE,
            self::TABLE . '.ndjson',
        );

        Assert::same($result['records'], [
            ['id' => 1, 'v' => 'a'],
            ['id' => 2, 'v' => 'b'],
        ]);
        Assert::same(
            $result['records'],
            $storage->read(self::TABLE, self::TABLE . '.ndjson'),
            'records must be exactly what read() returns',
        );

        Assert::count($result['broken'], 3);
        Assert::same($result['broken'][0]['line'], 1);
        Assert::same($result['broken'][0]['raw'], '{broken json');
        Assert::same($result['broken'][1]['line'], 3);
        Assert::same($result['broken'][1]['raw'], '"just a scalar"');
        Assert::same(
            $result['broken'][2]['line'],
            4,
            'a line that sanitizes to nothing is a loss on rewrite '
                . 'and must be flagged',
        );
    }

    #[Test]
    public function validateReportsBrokenRecordWithRawInContext(): void
    {
        $this->db->insert(self::TABLE, ['v' => 'a']);
        $this->db->insert(self::TABLE, ['v' => 'b']);
        $this->injectBrokenLine();

        $report = $this->db->validateTable(self::TABLE);
        $found = $report->issuesByCategory(IssueCategoryEnum::BROKEN_RECORD);

        Assert::count($found, 1, $report->format());
        Assert::same($found[0]->severity, IssueSeverityEnum::WARNING);
        Assert::same($found[0]->context['line'] ?? '', '2');
        Assert::string($found[0]->context['raw'] ?? '')
            ->contains('{CORRUPTED');
        Assert::false(
            $report->hasErrors(),
            'a broken line alone must not raise an error-grade report: '
                . $report->format(),
        );
    }

    #[Test]
    public function repairKeepsBrokenLineIntactAndRefusesOptimize(): void
    {
        $this->db->insert(self::TABLE, ['v' => 'a']);
        $this->db->insert(self::TABLE, ['v' => 'b']);
        $this->injectBrokenLine();

        $bytesBefore = file_get_contents($this->dataPath());

        $report = $this->db->repairTable(self::TABLE);

        Assert::same(
            file_get_contents($this->dataPath()),
            $bytesBefore,
            'repair must not rewrite a file holding unparseable lines',
        );

        $broken = $report->issuesByCategory(IssueCategoryEnum::BROKEN_RECORD);
        Assert::count($broken, 1, $report->format());
        Assert::false($broken[0]->repaired);
        Assert::null($broken[0]->repairError);

        $failed = $report->issuesByCategory(IssueCategoryEnum::REPAIR_FAILED);
        Assert::count($failed, 1, $report->format());
        Assert::string($failed[0]->message)->contains('unparseable');
    }

    #[Test]
    public function optimizeTableLeavesFileUntouchedOnBrokenLines(): void
    {
        $this->db->insert(self::TABLE, ['v' => 'b']);
        $this->db->insert(self::TABLE, ['v' => 'a']);
        $this->injectBrokenLine();

        $bytesBefore = file_get_contents($this->dataPath());

        $this->db->optimizeTable(self::TABLE);

        Assert::same(
            file_get_contents($this->dataPath()),
            $bytesBefore,
            'optimizeTable must refuse the rewrite, not drop the line',
        );
    }

    #[Test]
    public function cleanTableStillOptimizesAndRepairs(): void
    {
        $this->db->insert(self::TABLE, ['v' => 'b']);
        $this->db->insert(self::TABLE, ['v' => 'a']);

        $report = $this->db->repairTable(self::TABLE);
        $optimized = $report->issuesByCategory(
            IssueCategoryEnum::TABLE_OPTIMIZED,
        );

        Assert::count($optimized, 1, $report->format());
        Assert::true($optimized[0]->repaired);
    }

    private function injectBrokenLine(): void
    {
        file_put_contents(
            $this->dataPath(),
            '{CORRUPTED mid-file line' . "\n",
            FILE_APPEND,
        );
    }

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

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-broken-tests');
    }
}
