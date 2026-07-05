<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Integrity;

use AV\JsonProvider\Index\IndexKey;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;

/**
 * Read-only consistency validator.
 *
 * Walks every level of the storage and produces an IntegrityReport listing
 * deviations from the contract. The validator never mutates anything on disk.
 *
 * What is checked, in order:
 *
 *  - per-table:
 *    - the data file is present;
 *    - every declared index has its file;
 *    - the table subdirectory holds no orphan files (everything not declared
 *      in the schema);
 *    - every record's key order matches the schema columns order;
 *    - every index file is internally well-formed (`{key, line}` lines);
 *    - every index entry points to a real line in the data file;
 *    - the meta entry exists and matches the actual lineCount and the
 *      maximum stored id.
 *
 *  - per-database:
 *    - meta has no entries for tables not declared in the schema;
 *    - the DB root has no orphan directories or files (everything not
 *      `information_schema.json`, `meta.json`, or a known table subdir).
 *
 * "Records not sorted by id" is intentionally NOT a validator finding —
 * record order on disk is not a contract, only a repair preference.
 */
final class IntegrityValidator
{
    public function __construct(
        private readonly SchemaRegistry $schema,
        private readonly MetaRegistry $meta,
        private readonly NdjsonStorage $ndjson,
        private readonly JsonStorage $json,
        private readonly IndexManager $indexManager,
    ) {}

    /**
     * Validates a single table and returns the report.
     */
    public function validateTable(string $tableName): IntegrityReport
    {
        $start = microtime(true);
        $issues = $this->collectTableIssues($tableName);

        return new IntegrityReport(
            issues: $issues,
            tablesChecked: 1,
            tablesRepaired: 0,
            durationSeconds: microtime(true) - $start,
        );
    }

    /**
     * Validates the entire database and returns the report.
     */
    public function validateDatabase(): IntegrityReport
    {
        $start = microtime(true);
        $issues = [];

        $tables = $this->schema->getTables();

        foreach (array_keys($tables) as $tableName) {
            foreach ($this->collectTableIssues($tableName) as $issue) {
                $issues[] = $issue;
            }
        }

        foreach ($this->collectDatabaseIssues($tables) as $issue) {
            $issues[] = $issue;
        }

        return new IntegrityReport(
            issues: $issues,
            tablesChecked: \count($tables),
            tablesRepaired: 0,
            durationSeconds: microtime(true) - $start,
        );
    }

    /**
     * @return array<int,IntegrityIssue>
     */
    private function collectTableIssues(string $tableName): array
    {
        $issues = [];
        $tableSchema = $this->schema->getTable($tableName);

        if (!$this->ndjson->exists($tableName, $tableSchema->getFileName())) {
            $issues[] = new IntegrityIssue(
                IssueSeverity::ERROR,
                IssueCategory::TABLE_FILE_MISSING,
                $tableName,
                'data file ' . $tableSchema->getFileName() . ' is missing',
            );

            return $issues;
        }

        $records = $this->ndjson->read($tableName, $tableSchema->getFileName());

        foreach ($this->checkRecordKeyOrder($tableSchema, $records) as $i) {
            $issues[] = $i;
        }

        foreach ($this->checkIndexes($tableSchema, $records) as $i) {
            $issues[] = $i;
        }

        foreach ($this->checkOrphanFiles($tableSchema) as $i) {
            $issues[] = $i;
        }

        foreach ($this->checkMeta($tableSchema, $records) as $i) {
            $issues[] = $i;
        }

        return $issues;
    }

    /**
     * @param array<int,array<string,null|scalar>> $records
     *
     * @return array<int,IntegrityIssue>
     */
    private function checkRecordKeyOrder(
        TableSchema $tableSchema,
        array $records,
    ): array {
        $expected = array_keys($tableSchema->columns);
        $wrongCount = 0;

        foreach ($records as $record) {
            if (array_keys($record) !== $expected) {
                $wrongCount++;
            }
        }

        if ($wrongCount === 0) {
            return [];
        }

        return [new IntegrityIssue(
            IssueSeverity::WARNING,
            IssueCategory::RECORD_KEY_ORDER,
            $tableSchema->name,
            $wrongCount
                . ' record(s) have key order or set differing from schema',
        )];
    }

    /**
     * @param array<int,array<string,null|scalar>> $records
     *
     * @return array<int,IntegrityIssue>
     */
    private function checkIndexes(
        TableSchema $tableSchema,
        array $records,
    ): array {
        $issues = [];
        $maxLine = \count($records) - 1;

        foreach ($tableSchema->indexes as $index) {
            $fileName = $index->getFileName();

            if (!$this->ndjson->exists($tableSchema->name, $fileName)) {
                $issues[] = new IntegrityIssue(
                    IssueSeverity::ERROR,
                    IssueCategory::INDEX_FILE_MISSING,
                    $tableSchema->name,
                    'index "' . $index->name . '" file '
                        . $fileName . ' is missing',
                    context: ['index' => $index->name],
                );

                continue;
            }

            try {
                $entries = $this->indexManager->readIndex(
                    $tableSchema->name,
                    $index,
                );
            } catch (\Throwable $e) {
                $issues[] = new IntegrityIssue(
                    IssueSeverity::ERROR,
                    IssueCategory::INDEX_FILE_CORRUPT,
                    $tableSchema->name,
                    'index "' . $index->name . '" cannot be read: '
                        . $e->getMessage(),
                    context: ['index' => $index->name],
                );

                continue;
            }

            if (\count($entries) !== \count($records)) {
                $issues[] = new IntegrityIssue(
                    IssueSeverity::ERROR,
                    IssueCategory::INDEX_DRIFT,
                    $tableSchema->name,
                    'index "' . $index->name . '" has ' . \count($entries)
                        . ' entries vs ' . \count($records) . ' records',
                    context: ['index' => $index->name],
                );

                continue;
            }

            foreach ($entries as $entry) {
                if ($entry['line'] < 0 || $entry['line'] > $maxLine) {
                    $issues[] = new IntegrityIssue(
                        IssueSeverity::ERROR,
                        IssueCategory::INDEX_DRIFT,
                        $tableSchema->name,
                        'index "' . $index->name
                            . '" has dangling line reference '
                            . $entry['line'],
                        context: ['index' => $index->name],
                    );
                    break;
                }
            }

            $expectedKeys = [];

            foreach ($records as $line => $record) {
                $expectedKeys[$line] = IndexKey::build($record, $index);
            }

            foreach ($entries as $entry) {
                $line = $entry['line'];

                if (!isset($expectedKeys[$line])) {
                    continue;
                }

                if ($expectedKeys[$line] !== $entry['key']) {
                    $issues[] = new IntegrityIssue(
                        IssueSeverity::ERROR,
                        IssueCategory::INDEX_DRIFT,
                        $tableSchema->name,
                        'index "' . $index->name
                            . '" key mismatch for line ' . $line,
                        context: ['index' => $index->name],
                    );
                    break;
                }
            }
        }

        return $issues;
    }

    /**
     * @return array<int,IntegrityIssue>
     */
    private function checkOrphanFiles(TableSchema $tableSchema): array
    {
        if (!$this->ndjson->tableDirExists($tableSchema->name)) {
            return [];
        }

        $declared = [$tableSchema->getFileName()];

        foreach ($tableSchema->indexes as $index) {
            $declared[] = $index->getFileName();
        }

        $declaredSet = array_flip($declared);
        $issues = [];

        foreach ($this->ndjson->listFiles($tableSchema->name) as $fileName) {
            if (isset($declaredSet[$fileName])) {
                continue;
            }

            $issues[] = new IntegrityIssue(
                IssueSeverity::WARNING,
                IssueCategory::ORPHAN_INDEX_FILE,
                $tableSchema->name,
                'orphan file ' . $fileName . ' is not declared in the schema',
                context: ['file' => $fileName],
            );
        }

        return $issues;
    }

    /**
     * @param array<int,array<string,null|scalar>> $records
     *
     * @return array<int,IntegrityIssue>
     */
    private function checkMeta(TableSchema $tableSchema, array $records): array
    {
        if (!$this->meta->hasEntry($tableSchema->name)) {
            return [new IntegrityIssue(
                IssueSeverity::ERROR,
                IssueCategory::META_ENTRY_MISSING,
                $tableSchema->name,
                'meta entry is missing',
            )];
        }

        $issues = [];
        $actualCount = \count($records);
        $declaredCount = $this->meta->getLineCount($tableSchema->name);

        if ($actualCount !== $declaredCount) {
            $issues[] = new IntegrityIssue(
                IssueSeverity::ERROR,
                IssueCategory::META_LINE_COUNT_DRIFT,
                $tableSchema->name,
                'meta.lineCount=' . $declaredCount
                    . ' but actual records=' . $actualCount,
            );
        }

        $maxId = 0;

        foreach ($records as $record) {
            $id = $record[PrimaryKey::FIELD] ?? null;

            if (\is_int($id) && $id > $maxId) {
                $maxId = $id;
            }
        }

        $declaredLastId = $this->meta->getLastInsertedId($tableSchema->name);

        if ($declaredLastId < $maxId) {
            $issues[] = new IntegrityIssue(
                IssueSeverity::ERROR,
                IssueCategory::META_LAST_ID_DRIFT,
                $tableSchema->name,
                'meta.lastInsertedId=' . $declaredLastId
                    . ' but max(id)=' . $maxId,
            );
        }

        return $issues;
    }

    /**
     * @param array<string,TableSchema> $tables
     *
     * @return array<int,IntegrityIssue>
     */
    private function collectDatabaseIssues(array $tables): array
    {
        $issues = [];

        foreach ($this->meta->getTableNames() as $metaTable) {
            if (!isset($tables[$metaTable])) {
                $issues[] = new IntegrityIssue(
                    IssueSeverity::WARNING,
                    IssueCategory::META_ORPHAN_ENTRY,
                    $metaTable,
                    'meta has an entry for a table not declared in the schema',
                );
            }
        }

        $allowed = ['information_schema.json' => true, 'meta.json' => true];

        foreach (array_keys($tables) as $name) {
            $allowed[$name] = true;
        }

        foreach ($this->json->listRootEntries() as $entry) {
            if (isset($allowed[$entry['name']])) {
                continue;
            }

            $issues[] = new IntegrityIssue(
                IssueSeverity::WARNING,
                IssueCategory::ORPHAN_DB_ENTRY,
                null,
                ($entry['isDir'] ? 'orphan directory ' : 'orphan file ')
                    . $entry['name'],
                context: [
                    'name' => $entry['name'],
                    'kind' => $entry['isDir'] ? 'dir' : 'file',
                ],
            );
        }

        return $issues;
    }
}
