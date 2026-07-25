<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IssueCategory;
use AV\JsonProvider\Services\Integrity\IssueSeverity;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for index findings of the integrity validator and their repair:
 *
 *  - a pre-v2 indexFormat yields one INDEX_FORMAT_OUTDATED INFO issue per
 *    table (self-healing, so not an error) and suppresses the key-mismatch
 *    check (v1 keys vs the v2 encoder would false-alarm);
 *  - a line covered by zero or multiple entries yields INDEX_UNRELIABLE
 *    (ERROR) — the same structural definition the select path enforces;
 *  - repair rebuilds with the current encoder and stamps the format, and
 *    restore stamps after its rebuild.
 */
final class IndexFormatIntegrityTest
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
            uniqueConstraints: [],
            columns: ['id' => 'int', 'grp' => 'int'],
            indexes: [
                new IndexSchema(
                    name: 'idx_grp',
                    fields: [new IndexFieldSchema(
                        'grp',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));

        foreach ([1, 2, 1] as $grp) {
            $this->db->insert(self::TABLE, ['grp' => $grp]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function cleanTableReportsNoIndexIssues(): void
    {
        $report = $this->db->validateTable(self::TABLE);

        Assert::count(
            $report->issuesByCategory(IssueCategory::INDEX_FORMAT_OUTDATED),
            0,
        );
        Assert::count(
            $report->issuesByCategory(IssueCategory::INDEX_UNRELIABLE),
            0,
        );
    }

    #[Test]
    public function outdatedFormatIsInfoAndSkipsKeyMismatchCheck(): void
    {
        $this->dropIndexFormatMarker();

        $report = $this->db->validateTable(self::TABLE);

        $outdated = $report->issuesByCategory(
            IssueCategory::INDEX_FORMAT_OUTDATED,
        );
        Assert::count($outdated, 1);
        Assert::same($outdated[0]->severity, IssueSeverity::INFO);

        Assert::count(
            $report->issuesByCategory(IssueCategory::INDEX_DRIFT),
            0,
            'v1 keys must not be compared against the v2 encoder',
        );
        Assert::true(!$report->hasErrors());
    }

    #[Test]
    public function repairStampsOutdatedFormatAndRestoresIndexPath(): void
    {
        $this->dropIndexFormatMarker();

        $report = $this->db->repairTable(self::TABLE);

        $outdated = $report->issuesByCategory(
            IssueCategory::INDEX_FORMAT_OUTDATED,
        );
        Assert::count($outdated, 1);
        Assert::true($outdated[0]->repaired);
        Assert::same($this->metaIndexFormat(), 2);

        Assert::count(
            $this->db->table(self::TABLE)
                ->where('grp', '=', 1)->selectAllByArray(),
            2,
        );
    }

    #[Test]
    public function duplicateCoverageIsUnreliableErrorAndRepairs(): void
    {
        $path = $this->indexPath('idx_grp');
        $lines = explode("\n", trim((string)file_get_contents($path)));
        $first = json_decode($lines[0], true);
        \assert(\is_array($first));
        $second = json_decode($lines[1], true);
        \assert(\is_array($second));
        $second['line'] = $first['line'];
        $lines[1] = (string)json_encode($second);
        file_put_contents($path, implode("\n", $lines) . "\n");

        $report = $this->db->validateTable(self::TABLE);

        $unreliable = $report->issuesByCategory(
            IssueCategory::INDEX_UNRELIABLE,
        );
        Assert::count($unreliable, 1);
        Assert::same($unreliable[0]->severity, IssueSeverity::ERROR);
        Assert::string($unreliable[0]->message)
            ->contains('expected exactly 1');

        $repairReport = $this->db->repairTable(self::TABLE);
        $repaired = $repairReport->issuesByCategory(
            IssueCategory::INDEX_UNRELIABLE,
        );
        Assert::count($repaired, 1);
        Assert::true($repaired[0]->repaired);

        Assert::count(
            $this->db->validateTable(self::TABLE)
                ->issuesByCategory(IssueCategory::INDEX_UNRELIABLE),
            0,
        );
    }

    #[Test]
    public function legacyIntInFloatColumnCausesNoPhantomDrift(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'floats',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'f' => 'float'],
            indexes: [
                new IndexSchema(
                    name: 'idx_f',
                    fields: [new IndexFieldSchema(
                        'f',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));

        $big = 2 ** 53 + 1;
        $this->db->insert('floats', ['f' => (float)$big]);

        $path = $this->dbDir . '/floats/floats.ndjson';
        file_put_contents($path, '{"id":1,"f":' . $big . '}' . "\n");

        $report = $this->db->validateTable('floats');

        Assert::count(
            $report->issuesByCategory(IssueCategory::INDEX_DRIFT),
            0,
            'widened and raw reads must build identical keys',
        );
    }

    #[Test]
    public function nonFiniteStoredValueIsReportedNotThrown(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'inf_t',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'x' => 'float'],
            indexes: [
                new IndexSchema(
                    name: 'idx_x',
                    fields: [new IndexFieldSchema(
                        'x',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));
        $this->db->insert('inf_t', ['x' => 1.0]);

        $path = $this->dbDir . '/inf_t/inf_t.ndjson';
        file_put_contents(
            $path,
            str_replace(
                '"x":1.0',
                '"x":1e999',
                (string)file_get_contents($path),
            ),
        );

        $report = $this->db->validateTable('inf_t');

        Assert::int(
            \count($report->issuesByCategory(IssueCategory::INDEX_DRIFT)),
        )->greaterThan(0);
    }

    #[Test]
    public function restoreStampsIndexFormat(): void
    {
        $archive = self::dbPathRoot() . '/backup-' . uniqid() . '.tar.gz';
        $this->db->backup($archive);

        $this->dropIndexFormatMarker();

        $this->db->restore($archive);

        Assert::same($this->metaIndexFormat(), 2);
        Assert::count(
            $this->db->table(self::TABLE)
                ->where('grp', '=', 1)->selectAllByArray(),
            2,
        );
    }

    private function indexPath(string $indexName): string
    {
        return $this->dbDir . '/' . self::TABLE . '/' . $indexName
            . '.index.ndjson';
    }

    private function dropIndexFormatMarker(): void
    {
        $path = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($path), true);
        \assert(\is_array($meta) && \is_array($meta[self::TABLE]));
        unset($meta[self::TABLE]['indexFormat']);
        file_put_contents($path, json_encode($meta));
    }

    private function metaIndexFormat(): int | null
    {
        $meta = json_decode(
            (string)file_get_contents($this->dbDir . '/meta.json'),
            true,
        );
        \assert(\is_array($meta) && \is_array($meta[self::TABLE]));

        $format = $meta[self::TABLE]['indexFormat'] ?? null;

        return \is_int($format) ? $format : null;
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
        return TempDir::root('jp-ixintegrity-tests');
    }
}
