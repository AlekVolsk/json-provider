<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Services\Integrity\IssueCategory;
use AV\JsonProvider\Services\Integrity\IssueSeverity;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the database-level relational integrity checks
 * (bi-fk-unique-integrity-checks): stored FK values without a matching
 * parent key are reported as fk_orphan (contextual severity), unique
 * constraint violations in the stored data as unique_duplicate; both are
 * report-only — repair never rewrites data to make them disappear.
 * Declaration-level breakage reachable only through hand-edits (missing
 * table or column, base type mismatch) is reported instead of silently
 * skipping the relation.
 */
final class FkUniqueIntegrityTest
{
    private const string DB_PATH = '/tmp/jp-fkuniq-tests';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: 'owners',
            columns: ['id' => 'int', 'name' => 'string'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'pets',
            columns: [
                'id'       => 'int',
                'owner_id' => 'int|null',
                'nick'     => 'string',
            ],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'codes',
            uniqueConstraints: [
                new UniqueConstraint('u_code', ['code']),
            ],
            columns: ['id' => 'int', 'code' => 'string|null'],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    #[Test]
    public function danglingFkIsReportedAndNullFkIsClean(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'pets',
            foreignKey: 'owner_id',
            toTable: 'owners',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::NO_ACTION,
        ));

        $ownerId = $this->db->insert('owners', ['name' => 'alive']);
        $this->db->insert('pets', ['owner_id' => $ownerId, 'nick' => 'ok']);
        $this->db->insert('pets', ['owner_id' => null, 'nick' => 'free']);
        $this->db->insert('pets', ['owner_id' => 999, 'nick' => 'lost']);

        $report = $this->db->validate();
        $found = $report->issuesByCategory(IssueCategory::FK_ORPHAN);

        Assert::count($found, 1, $report->format());
        Assert::same($found[0]->severity, IssueSeverity::INFO);
        Assert::same($found[0]->tableName, 'pets');
        Assert::string($found[0]->message)->contains('999');
        Assert::string($found[0]->message)->contains('owners');
        Assert::same($found[0]->context['line'] ?? '', '2');
    }

    #[Test]
    public function singleTableValidateDoesNotRunRelationalPass(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'pets',
            foreignKey: 'owner_id',
            toTable: 'owners',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::NO_ACTION,
        ));
        $this->db->insert('pets', ['owner_id' => 999, 'nick' => 'lost']);

        $report = $this->db->validateTable('pets');

        Assert::count(
            $report->issuesByCategory(IssueCategory::FK_ORPHAN),
            0,
            'the cross-table orphan pass belongs to the database-level '
                . 'validate: ' . $report->format(),
        );
    }

    #[Test]
    public function typeMismatchIsReportedOnceAndValuesAreSkipped(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'pets',
            foreignKey: 'owner_id',
            toTable: 'owners',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::NO_ACTION,
        ));
        $this->db->insert('pets', ['owner_id' => 999, 'nick' => 'lost']);

        $this->rewriteSchemaJson(
            static function (array $schema): array {
                \assert(\is_array($schema['tables']));
                \assert(\is_array($schema['tables']['pets']));
                \assert(\is_array($schema['tables']['pets']['columns']));
                $schema['tables']['pets']['columns']['owner_id']
                    = 'string|null';

                return $schema;
            },
        );

        $report = $this->db->validate();
        $found = $report->issuesByCategory(IssueCategory::FK_ORPHAN);

        Assert::count($found, 1, $report->format());
        Assert::same($found[0]->severity, IssueSeverity::WARNING);
        Assert::string($found[0]->message)->contains('type mismatch');
    }

    #[Test]
    public function relationToMissingTableIsReportedNotSilent(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'pets',
            foreignKey: 'owner_id',
            toTable: 'owners',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::NO_ACTION,
        ));

        $this->rewriteSchemaJson(
            static function (array $schema): array {
                \assert(\is_array($schema['relations']));
                \assert(\is_array($schema['relations'][0]));
                $schema['relations'][0]['to'] = 'ghosts';

                return $schema;
            },
        );

        $report = $this->db->validate();
        $found = $report->issuesByCategory(IssueCategory::FK_ORPHAN);

        Assert::count($found, 1, $report->format());
        Assert::string($found[0]->message)->contains('missing table');
        Assert::string($found[0]->message)->contains('ghosts');
    }

    #[Test]
    public function uniqueDuplicateInStoredDataIsReported(): void
    {
        $this->db->insert('codes', ['code' => 'AAA']);
        $this->db->insert('codes', ['code' => 'BBB']);

        $path = $this->dbDir . '/codes/codes.ndjson';
        $raw = (string)file_get_contents($path);
        file_put_contents($path, str_replace('BBB', 'AAA', $raw));

        $report = $this->db->validate();
        $found = $report->issuesByCategory(IssueCategory::UNIQUE_DUPLICATE);

        Assert::count($found, 1, $report->format());
        Assert::same($found[0]->severity, IssueSeverity::WARNING);
        Assert::string($found[0]->message)->contains('u_code');
        Assert::same($found[0]->context['lines'] ?? '', '0,1');
    }

    #[Test]
    public function uniqueDuplicateIsFoundBySingleTableValidateToo(): void
    {
        $this->db->insert('codes', ['code' => 'AAA']);
        $this->db->insert('codes', ['code' => 'BBB']);

        $path = $this->dbDir . '/codes/codes.ndjson';
        $raw = (string)file_get_contents($path);
        file_put_contents($path, str_replace('BBB', 'AAA', $raw));

        $report = $this->db->validateTable('codes');

        Assert::count(
            $report->issuesByCategory(IssueCategory::UNIQUE_DUPLICATE),
            1,
            $report->format(),
        );
    }

    #[Test]
    public function nullsNeverViolateUnique(): void
    {
        $this->db->insert('codes', ['code' => null]);
        $this->db->insert('codes', ['code' => null]);

        $report = $this->db->validate();

        Assert::count(
            $report->issuesByCategory(IssueCategory::UNIQUE_DUPLICATE),
            0,
            $report->format(),
        );
    }

    #[Test]
    public function relationalFindingsAreNotAutoRepaired(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'pets',
            foreignKey: 'owner_id',
            toTable: 'owners',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::NO_ACTION,
        ));
        $this->db->insert('pets', ['owner_id' => 999, 'nick' => 'lost']);

        $this->db->insert('codes', ['code' => 'AAA']);
        $this->db->insert('codes', ['code' => 'BBB']);
        $path = $this->dbDir . '/codes/codes.ndjson';
        file_put_contents(
            $path,
            str_replace('BBB', 'AAA', (string)file_get_contents($path)),
        );

        $report = $this->db->repair();

        foreach (
            [IssueCategory::FK_ORPHAN, IssueCategory::UNIQUE_DUPLICATE] as $cat
        ) {
            $found = $report->issuesByCategory($cat);
            Assert::count($found, 1, $cat->value . ': ' . $report->format());
            Assert::false($found[0]->repaired);
        }

        Assert::count(
            $this->db->table('pets')
                ->where('owner_id', '=', 999)->selectAllByArray(),
            1,
            'repair must never delete the orphan row',
        );
        Assert::count(
            $this->db->table('codes')
                ->where('code', '=', 'AAA')->selectAllByArray(),
            2,
            'repair must never delete the duplicate row',
        );
    }

    /**
     * @param callable(array<mixed>): array<mixed> $mutate
     */
    private function rewriteSchemaJson(callable $mutate): void
    {
        $path = $this->dbDir . '/information_schema.json';
        $schema = json_decode((string)file_get_contents($path), true);
        \assert(\is_array($schema));
        file_put_contents(
            $path,
            json_encode($mutate($schema), JSON_PRETTY_PRINT),
        );
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
