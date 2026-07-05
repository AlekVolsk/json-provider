<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Services\Integrity\IntegrityReport;
use AV\JsonProvider\Services\Integrity\IssueCategory;
use AV\JsonProvider\Services\Integrity\IssueSeverity;
use AV\JsonProvider\Tests\Support\Fixture;
use Testo\Assert;
use Testo\Test;

/**
 * Tests for IntegrityValidator and IntegrityRepairer (and the provider's
 * validate/repair/optimizeTable APIs).
 *
 * The validator is read-only; the repairer never throws past its boundary.
 */
final class IntegrityTest
{
    #[Test]
    public function validateOnCleanFixtureReportsNoIssues(): void
    {
        $report = Fixture::db()->validate();

        Assert::same($report->tablesChecked, 3);
        Assert::false($report->hasErrors(), $report->format());
        Assert::false($report->hasWarnings(), $report->format());
    }

    #[Test]
    public function validateDetectsMissingIndexFile(): void
    {
        unlink(Fixture::DB_PATH . '/products/idx_category.index.ndjson');

        $report = Fixture::db()->validateTable('products');

        Assert::iterable(
            $report->issuesByCategory(IssueCategory::INDEX_FILE_MISSING),
        )->notEmpty();

        Fixture::db()->repairTable('products');
    }

    #[Test]
    public function validateDetectsIndexDriftAfterTampering(): void
    {
        file_put_contents(
            Fixture::DB_PATH . '/products/idx_category.index.ndjson',
            "{\"key\":\"deadbeef\",\"line\":99999}\n",
        );

        $report = Fixture::db()->validateTable('products');

        Assert::iterable(
            $report->issuesByCategory(IssueCategory::INDEX_DRIFT),
        )->notEmpty();

        Fixture::db()->repairTable('products');
    }

    #[Test]
    public function validateDetectsOrphanIndexFile(): void
    {
        $orphan = Fixture::DB_PATH . '/products/idx_unused.index.ndjson';
        file_put_contents($orphan, '');

        $report = Fixture::db()->validateTable('products');
        $issues = $report->issuesByCategory(
            IssueCategory::ORPHAN_INDEX_FILE,
        );

        Assert::iterable($issues)->notEmpty();

        $first = $issues[0] ?? null;
        Assert::notNull($first);
        Assert::same(
            $first->context['file'] ?? null,
            'idx_unused.index.ndjson',
        );

        Fixture::db()->repairTable('products');
        Assert::false(file_exists($orphan));
    }

    #[Test]
    public function validateDetectsMetaLineCountDrift(): void
    {
        $metaFile = Fixture::DB_PATH . '/meta.json';
        $meta = $this->readMeta($metaFile);
        $original = $meta['products']['lineCount'];
        $meta['products']['lineCount'] = 999;
        file_put_contents(
            $metaFile,
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        $report = Fixture::db()->validateTable('products');

        Assert::iterable(
            $report->issuesByCategory(IssueCategory::META_LINE_COUNT_DRIFT),
        )->notEmpty();

        Fixture::db()->repairTable('products');

        $metaAfter = $this->readMeta($metaFile);
        Assert::same($metaAfter['products']['lineCount'], $original);
    }

    #[Test]
    public function validateDetectsDbOrphanFile(): void
    {
        $orphan = Fixture::DB_PATH . '/junk.txt';
        file_put_contents($orphan, 'random');

        $report = Fixture::db()->validate();
        $issues = $report->issuesByCategory(IssueCategory::ORPHAN_DB_ENTRY);

        Assert::iterable($issues)->notEmpty();

        Fixture::db()->repair();

        Assert::false(file_exists($orphan));
    }

    #[Test]
    public function repairSucceedsAndProducesRepairedFlags(): void
    {
        $orphan = Fixture::DB_PATH . '/products/idx_extra.index.ndjson';
        file_put_contents($orphan, '');

        $report = Fixture::db()->repairTable('products');

        $orphanIssues = $report->issuesByCategory(
            IssueCategory::ORPHAN_INDEX_FILE,
        );
        Assert::iterable($orphanIssues)->notEmpty();

        foreach ($orphanIssues as $issue) {
            Assert::true($issue->repaired, $issue->message);
            Assert::null($issue->repairError);
        }

        $optimizeIssues = $report->issuesByCategory(
            IssueCategory::TABLE_OPTIMIZED,
        );
        Assert::count($optimizeIssues, 1);

        $optimized = $optimizeIssues[0] ?? null;
        Assert::notNull($optimized);
        Assert::true($optimized->repaired);
    }

    #[Test]
    public function optimizeTableSortsRecordsByIdAndRebuildsIndexes(): void
    {
        $db = Fixture::db();
        $tbl = $db->table('categories');

        $idA = $tbl->insertByArray(['name' => 'OptZ', 'sort' => 1]);
        $idB = $tbl->insertByArray(['name' => 'OptA', 'sort' => 2]);

        $tbl->optimizeTable();

        $path = Fixture::DB_PATH . '/categories/categories.ndjson';
        $lines = array_filter(
            explode("\n", (string)file_get_contents($path)),
            static fn (string $l): bool => trim($l) !== '',
        );

        $ids = [];

        foreach ($lines as $line) {
            $row = json_decode($line, true);
            \assert(\is_array($row));
            $ids[] = $row['id'];
        }

        $sorted = $ids;
        sort($sorted);
        Assert::same(
            $ids,
            $sorted,
            'records must be physically sorted by id ASC',
        );

        $tbl->deleteById($idA);
        $tbl->deleteById($idB);
    }

    #[Test]
    public function reportFormatIsHumanReadable(): void
    {
        $report = Fixture::db()->validate();

        $text = $report->format();

        Assert::string($text)->contains('JsonProvider integrity report');
        Assert::string($text)->contains('tables checked: 3');

        // Add a known issue and re-format.
        file_put_contents(
            Fixture::DB_PATH . '/products/junk.index.ndjson',
            '',
        );

        $textWith = Fixture::db()->validate()->format();
        Assert::string($textWith)->contains('orphan_index_file');

        Fixture::db()->repair();
    }

    #[Test]
    public function validatorIsReadOnly(): void
    {
        $countBefore = Fixture::db()->table('products')->count();
        $metaBefore = file_get_contents(Fixture::DB_PATH . '/meta.json');

        Fixture::db()->validate();

        $countAfter = Fixture::db()->table('products')->count();
        $metaAfter = file_get_contents(Fixture::DB_PATH . '/meta.json');

        Assert::same($countAfter, $countBefore);
        Assert::same($metaAfter, $metaBefore);
    }

    #[Test]
    public function reportIsTypedReport(): void
    {
        $report = Fixture::db()->validate();
        Assert::instanceOf($report, IntegrityReport::class);
        Assert::same(IssueSeverity::from('error'), IssueSeverity::ERROR);
    }

    /**
     * Reads meta.json into a strictly typed structure for test assertions.
     *
     * @return array<string,array{lastInsertedId:int,lineCount:int}>
     */
    private function readMeta(string $path): array
    {
        $raw = file_get_contents($path);
        \assert($raw !== false);

        /** @var array<string,array{lastInsertedId:int,lineCount:int}> $meta */
        $meta = json_decode($raw, true);
        \assert(isset($meta['products']));

        return $meta;
    }
}
