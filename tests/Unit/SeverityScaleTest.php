<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Integrity\IssueCategoryEnum;
use AV\JsonProvider\Services\Integrity\IssueSeverityEnum;
use AV\JsonProvider\Tests\Support\SpyLogger;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the unified severity scale: the CRITICAL level, the PSR-3
 * mapping and rank ordering, the per-category layout at the validator's
 * emission sites, the contextual FK_ORPHAN severity, critical-first
 * report sorting, and the root logger (one log record per finding at the
 * finding's psrLevel; runtime index degradations logged as info, the
 * INDEX_UNRELIABLE throw as error).
 */
final class SeverityScaleTest
{
    private string $dbDir;

    private JsonDataProvider $db;

    private SpyLogger $logger;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->logger = new SpyLogger();
        $this->db = JsonDataProvider::createDatabase(
            $this->dbDir,
            null,
            $this->logger,
        );

        $this->db->createTable(TableSchema::create(
            name: 'parents',
            columns: ['id' => 'int', 'name' => 'string'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'childs',
            columns: [
                'id'        => 'int',
                'parent_id' => 'int|null',
                'label'     => 'string',
            ],
            indexes: [
                new IndexSchema(
                    name: 'idx_label',
                    fields: [new IndexFieldSchema(
                        'label',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function severityEnumMapsToPsrLevelsAndRanks(): void
    {
        Assert::same(IssueSeverityEnum::CRITICAL->psrLevel(), 'critical');
        Assert::same(IssueSeverityEnum::ERROR->psrLevel(), 'error');
        Assert::same(IssueSeverityEnum::WARNING->psrLevel(), 'warning');
        Assert::same(IssueSeverityEnum::INFO->psrLevel(), 'info');

        Assert::same(IssueSeverityEnum::CRITICAL->rank(), 0);
        Assert::same(IssueSeverityEnum::ERROR->rank(), 1);
        Assert::same(IssueSeverityEnum::WARNING->rank(), 2);
        Assert::same(IssueSeverityEnum::INFO->rank(), 3);

        Assert::same(
            IssueSeverityEnum::from('critical'),
            IssueSeverityEnum::CRITICAL,
        );
    }

    #[Test]
    public function tableFileMissingIsCritical(): void
    {
        $this->db->insert('parents', ['name' => 'p1']);
        unlink($this->dbDir . '/parents/parents.ndjson');

        $report = $this->db->validateTable('parents');
        $found = $report->issuesByCategory(
            IssueCategoryEnum::TABLE_FILE_MISSING,
        );

        Assert::count($found, 1, $report->format());
        Assert::same($found[0]->severity, IssueSeverityEnum::CRITICAL);
        Assert::true(
            $report->hasErrors(),
            'CRITICAL must count as an error in hasErrors()',
        );
    }

    #[Test]
    public function metaEntryMissingIsCritical(): void
    {
        $this->db->insert('parents', ['name' => 'p1']);
        $this->dropMetaEntry('parents');

        $report = $this->db->validateTable('parents');
        $found = $report->issuesByCategory(
            IssueCategoryEnum::META_ENTRY_MISSING,
        );

        Assert::count($found, 1, $report->format());
        Assert::same($found[0]->severity, IssueSeverityEnum::CRITICAL);
    }

    #[Test]
    public function metaEntryCorruptIsCritical(): void
    {
        $this->db->insert('parents', ['name' => 'p1']);
        $this->corruptMetaEntry('parents');

        $report = $this->db->validateTable('parents');
        $found = $report->issuesByCategory(
            IssueCategoryEnum::META_ENTRY_CORRUPT,
        );

        Assert::count($found, 1, $report->format());
        Assert::same($found[0]->severity, IssueSeverityEnum::CRITICAL);
    }

    #[Test]
    public function indexFileMissingIsErrorNotCritical(): void
    {
        $this->db->insert('childs', ['parent_id' => null, 'label' => 'x']);
        unlink($this->dbDir . '/childs/idx_label.index.ndjson');

        $report = $this->db->validateTable('childs');
        $found = $report->issuesByCategory(
            IssueCategoryEnum::INDEX_FILE_MISSING,
        );

        Assert::count($found, 1, $report->format());
        Assert::same($found[0]->severity, IssueSeverityEnum::ERROR);
    }

    #[Test]
    public function fkOrphanSeverityFollowsTheDeclaredAction(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'childs',
            foreignKey: 'parent_id',
            toTable: 'parents',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::NO_ACTION,
        ));

        $this->db->insert('childs', ['parent_id' => 777, 'label' => 'lost']);

        $report = $this->db->validate();
        $found = $report->issuesByCategory(IssueCategoryEnum::FK_ORPHAN);

        Assert::count($found, 1, $report->format());
        Assert::same(
            $found[0]->severity,
            IssueSeverityEnum::INFO,
            'an orphan under noAction is an accepted fact of the data',
        );

        $this->db->dropRelation('childs', 'parent_id', 'parents');
        $this->db->addRelation(new RelationSchema(
            fromTable: 'childs',
            foreignKey: 'parent_id',
            toTable: 'parents',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        $report = $this->db->validate();
        $found = $report->issuesByCategory(IssueCategoryEnum::FK_ORPHAN);

        Assert::count($found, 1, $report->format());
        Assert::same(
            $found[0]->severity,
            IssueSeverityEnum::WARNING,
            'an orphan under an enforced action means the enforcement '
                . 'was bypassed',
        );
    }

    #[Test]
    public function reportIsSortedCriticalFirst(): void
    {
        $this->db->insert('childs', ['parent_id' => null, 'label' => 'x']);
        unlink($this->dbDir . '/childs/idx_label.index.ndjson');
        file_put_contents($this->dbDir . '/childs/stray.bin', 'junk');
        $this->dropMetaEntry('childs');

        $report = $this->db->validateTable('childs');

        Assert::true(\count($report->issues) >= 3, $report->format());

        $ranks = array_map(
            static fn ($issue): int => $issue->severity->rank(),
            $report->issues,
        );
        $sorted = $ranks;
        sort($sorted);

        Assert::same($ranks, $sorted, $report->format());
        Assert::same(
            $report->issues[0]->category,
            IssueCategoryEnum::META_ENTRY_MISSING,
        );
    }

    #[Test]
    public function loggerReceivesEachFindingOnceAtItsPsrLevel(): void
    {
        $this->db->insert('childs', ['parent_id' => null, 'label' => 'x']);
        unlink($this->dbDir . '/childs/idx_label.index.ndjson');
        file_put_contents($this->dbDir . '/childs/stray.bin', 'junk');

        $report = $this->db->validateTable('childs');

        Assert::count($this->logger->records, \count($report->issues));

        $reportLevels = array_map(
            static fn ($issue): string => $issue->severity->psrLevel(),
            $report->issues,
        );
        sort($reportLevels);
        $loggedLevels = array_column($this->logger->records, 'level');
        sort($loggedLevels);

        Assert::same($loggedLevels, $reportLevels);
    }

    #[Test]
    public function validationWithoutLoggerStaysSilentAndWorks(): void
    {
        $bare = JsonDataProvider::createDatabase(
            self::dbPathRoot() . '/' . uniqid('bare', true),
        );
        $bare->createTable(TableSchema::create(
            name: 'items',
            columns: ['id' => 'int', 'v' => 'string'],
        ));
        $bare->insert('items', ['v' => 'a']);

        $report = $bare->validate();

        Assert::false($report->hasErrors(), $report->format());
    }

    #[Test]
    public function byteSizeDesyncDegradesToFullScanAndLogsInfo(): void
    {
        $this->db->insert('childs', ['parent_id' => null, 'label' => 'aa']);
        $this->db->insert('childs', ['parent_id' => null, 'label' => 'bb']);

        file_put_contents(
            $this->dbDir . '/childs/childs.ndjson',
            '{"id":99,"parent_id":null,"label":"foreign"}' . "\n",
            FILE_APPEND,
        );

        $rows = $this->db->table('childs')
            ->where('label', '=', 'foreign')->selectAllByArray();

        Assert::count(
            $rows,
            1,
            'the foreign append must be visible through the full scan',
        );

        $infoRecords = array_filter(
            $this->logger->records,
            static fn (array $r): bool => $r['level'] === 'info'
                && str_contains($r['message'], 'full scan'),
        );
        Assert::true(
            $infoRecords !== [],
            'the silent index degradation must be logged as info',
        );
    }

    #[Test]
    public function indexUnreliableThrowIsLoggedAsError(): void
    {
        $this->db->insert('childs', ['parent_id' => null, 'label' => 'aa']);
        $this->db->insert('childs', ['parent_id' => null, 'label' => 'bb']);

        $indexPath = $this->dbDir . '/childs/idx_label.index.ndjson';
        $lines = explode(
            "\n",
            trim((string)file_get_contents($indexPath)),
        );
        Assert::count($lines, 2);
        file_put_contents(
            $indexPath,
            $lines[0] . "\n" . $lines[0] . "\n",
        );

        try {
            $this->db->table('childs')
                ->where('label', '=', 'aa')->selectAllByArray();
            Assert::fail('a structurally corrupt index must throw');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'IndexBrokenPermutation');
        }

        $errorRecords = array_filter(
            $this->logger->records,
            static fn (array $r): bool => $r['level'] === 'error',
        );
        Assert::true(
            $errorRecords !== [],
            'the INDEX_UNRELIABLE throw must be logged as error',
        );
    }

    private function dropMetaEntry(string $table): void
    {
        $metaPath = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($metaPath), true);
        \assert(\is_array($meta));
        unset($meta[$table]);
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT));
    }

    private function corruptMetaEntry(string $table): void
    {
        $metaPath = $this->dbDir . '/meta.json';
        $meta = json_decode((string)file_get_contents($metaPath), true);
        \assert(\is_array($meta) && \is_array($meta[$table]));
        $meta[$table]['lineCount'] = 'one';
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT));
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
        return TempDir::root('jp-severity-tests');
    }
}
