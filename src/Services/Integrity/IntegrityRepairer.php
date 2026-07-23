<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Integrity;

use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;

/**
 * Repairs deviations found by IntegrityValidator.
 *
 * The repairer never throws past its own boundary — every per-issue action
 * is wrapped in a try/catch and recorded in the issue itself
 * (`repaired=true` on success, `repairError=...` on failure). The full
 * report is returned regardless of partial failures.
 *
 * Repair actions, by category:
 *
 *  - INDEX_FILE_MISSING / INDEX_FILE_CORRUPT / INDEX_DRIFT:
 *      ensure the file exists, then rebuild from current data.
 *  - ORPHAN_INDEX_FILE: delete.
 *  - RECORD_KEY_ORDER: full table rewrite (records normalized + indexes
 *    rebuilt).
 *  - META_ENTRY_MISSING: re-init meta entry, then re-derive lineCount and
 *      lastInsertedId from data.
 *  - META_LINE_COUNT_DRIFT: commitRewrite with the actual count and size.
 *  - META_LAST_ID_DRIFT: setLastInsertedId to max(id).
 *  - META_ORPHAN_ENTRY: drop entry from meta.
 *  - ORPHAN_DB_ENTRY: delete the file; a directory is removed only when
 *      every file in it is empty (non-empty orphans need a manual call).
 *  - TABLE_FILE_MISSING: provision an empty data file, missing index files
 *      and the meta entry — the createTable crash window; lost data is not
 *      invented.
 *
 * After per-issue repair, an explicit "table optimize" pass is run for every
 * touched table (sort records by id ASC + rebuild every index), recorded as
 * a TABLE_OPTIMIZED info issue per table.
 *
 * The repairer reads machine-readable data from IntegrityIssue::$context;
 * it does not parse the human-readable $message.
 */
final class IntegrityRepairer
{
    public function __construct(
        private readonly IntegrityValidator $validator,
        private readonly SchemaRegistry $schema,
        private readonly MetaRegistry $meta,
        private readonly NdjsonStorage $ndjson,
        private readonly JsonStorage $json,
        private readonly IndexManager $indexManager,
    ) {}

    public function repairTable(string $tableName): IntegrityReport
    {
        $start = microtime(true);
        $issues = $this->validator->validateTable($tableName)->issues;
        $issues = $this->repairIssues($issues);

        try {
            $issues[] = $this->optimizeTable($tableName);
        } catch (\Throwable $e) {
            $issues[] = new IntegrityIssue(
                IssueSeverity::ERROR,
                IssueCategory::REPAIR_FAILED,
                $tableName,
                'optimizeTable failed: ' . $e->getMessage(),
            );
        }

        return new IntegrityReport(
            issues: $issues,
            tablesChecked: 1,
            tablesRepaired: 1,
            durationSeconds: microtime(true) - $start,
        );
    }

    public function repairDatabase(): IntegrityReport
    {
        $start = microtime(true);
        $report = $this->validator->validateDatabase();
        $issues = $this->repairIssues($report->issues);

        $tables = $this->schema->getTables();

        foreach (array_keys($tables) as $tableName) {
            try {
                $issues[] = $this->optimizeTable($tableName);
            } catch (\Throwable $e) {
                $issues[] = new IntegrityIssue(
                    IssueSeverity::ERROR,
                    IssueCategory::REPAIR_FAILED,
                    $tableName,
                    'optimizeTable failed: ' . $e->getMessage(),
                );
            }
        }

        return new IntegrityReport(
            issues: $issues,
            tablesChecked: $report->tablesChecked,
            tablesRepaired: \count($tables),
            durationSeconds: microtime(true) - $start,
        );
    }

    /**
     * Sorts the table records by id ASC and rebuilds every index.
     * Also re-derives meta.lineCount from the actual row count.
     *
     * Used both as a standalone preventive optimization and as a final pass
     * inside repair operations.
     *
     * Returns an INFO issue describing the result (TABLE_OPTIMIZED).
     */
    public function optimizeTable(string $tableName): IntegrityIssue
    {
        $tableSchema = $this->schema->getTable($tableName);
        $records = $this->ndjson->read($tableName, $tableSchema->getFileName());

        $records = $this->normalizeAndSort($tableSchema, $records);

        $byteSize = $this->ndjson->write(
            $tableName,
            $tableSchema->getFileName(),
            $records,
        );
        $this->indexManager->rebuild($tableSchema, $records);
        $this->meta->commitRewrite($tableName, \count($records), $byteSize);

        return new IntegrityIssue(
            IssueSeverity::INFO,
            IssueCategory::TABLE_OPTIMIZED,
            $tableName,
            'records sorted by id, all indexes rebuilt ('
                . \count($records) . ' rows)',
            repaired: true,
        );
    }

    /**
     * @param array<int,IntegrityIssue> $issues
     *
     * @return array<int,IntegrityIssue>
     */
    private function repairIssues(array $issues): array
    {
        $result = [];

        foreach ($issues as $issue) {
            $result[] = $this->repairOne($issue);
        }

        return $result;
    }

    private function repairOne(IntegrityIssue $issue): IntegrityIssue
    {
        try {
            return match ($issue->category) {
                IssueCategory::INDEX_FILE_MISSING,
                IssueCategory::INDEX_FILE_CORRUPT,
                IssueCategory::INDEX_DRIFT       => $this->repairIndex($issue),
                IssueCategory::ORPHAN_INDEX_FILE => $this
                    ->repairOrphanIndexFile($issue),
                IssueCategory::RECORD_KEY_ORDER => $this
                    ->repairRecordKeyOrder($issue),
                IssueCategory::META_ENTRY_MISSING => $this
                    ->repairMetaEntryMissing($issue),
                IssueCategory::META_LINE_COUNT_DRIFT => $this
                    ->repairMetaLineCount($issue),
                IssueCategory::META_LAST_ID_DRIFT => $this
                    ->repairMetaLastId($issue),
                IssueCategory::META_ORPHAN_ENTRY => $this
                    ->repairMetaOrphanEntry($issue),
                IssueCategory::ORPHAN_DB_ENTRY => $this
                    ->repairOrphanDbEntry($issue),
                IssueCategory::TABLE_FILE_MISSING => $this
                    ->repairTableFileMissing($issue),
                default => $issue,
            };
        } catch (\Throwable $e) {
            return $issue->withRepairError($e->getMessage());
        }
    }

    private function repairIndex(IntegrityIssue $issue): IntegrityIssue
    {
        $tableName = (string)$issue->tableName;
        $indexName = $issue->context['index'] ?? null;

        if ($indexName === null) {
            return $issue->withRepairError('issue context has no "index" key');
        }

        $tableSchema = $this->schema->getTable($tableName);
        $indexSchema = $this->indexManager->findIndex($tableSchema, $indexName);

        if (!$this->ndjson->exists($tableName, $indexSchema->getFileName())) {
            $this->ndjson->createFile($tableName, $indexSchema->getFileName());
        }

        $records = $this->ndjson->read($tableName, $tableSchema->getFileName());
        $this->indexManager->rebuildOne($tableName, $indexSchema, $records);

        return $issue->withRepaired();
    }

    private function repairOrphanIndexFile(
        IntegrityIssue $issue,
    ): IntegrityIssue {
        $tableName = (string)$issue->tableName;
        $fileName = $issue->context['file'] ?? null;

        if ($fileName === null) {
            return $issue->withRepairError('issue context has no "file" key');
        }

        $this->ndjson->deleteFile($tableName, $fileName);

        return $issue->withRepaired();
    }

    private function repairRecordKeyOrder(IntegrityIssue $issue): IntegrityIssue
    {
        $tableName = (string)$issue->tableName;
        $tableSchema = $this->schema->getTable($tableName);
        $records = $this->ndjson->read($tableName, $tableSchema->getFileName());
        $records = array_map(
            fn (array $r): array => $this->normalizeRecord($tableSchema, $r),
            $records,
        );

        $this->ndjson->write($tableName, $tableSchema->getFileName(), $records);
        $this->indexManager->rebuild($tableSchema, $records);

        return $issue->withRepaired();
    }

    private function repairMetaEntryMissing(
        IntegrityIssue $issue,
    ): IntegrityIssue {
        $tableName = (string)$issue->tableName;
        $tableSchema = $this->schema->getTable($tableName);

        $tail = $this->ndjson->repairTail(
            $tableName,
            $tableSchema->getFileName(),
        );
        $records = $this->ndjson->read($tableName, $tableSchema->getFileName());

        $this->meta->initTable($tableName);
        $this->meta->setLastInsertedId($tableName, $this->maxId($records));
        $this->meta->commitRewrite($tableName, \count($records), $tail['size']);

        return $issue->withRepaired();
    }

    private function repairMetaLineCount(IntegrityIssue $issue): IntegrityIssue
    {
        $tableName = (string)$issue->tableName;
        $tableSchema = $this->schema->getTable($tableName);

        $tail = $this->ndjson->repairTail(
            $tableName,
            $tableSchema->getFileName(),
        );
        $records = $this->ndjson->read($tableName, $tableSchema->getFileName());

        $this->meta->commitRewrite($tableName, \count($records), $tail['size']);

        return $issue->withRepaired();
    }

    private function repairMetaLastId(IntegrityIssue $issue): IntegrityIssue
    {
        $tableName = (string)$issue->tableName;
        $tableSchema = $this->schema->getTable($tableName);
        $records = $this->ndjson->read($tableName, $tableSchema->getFileName());

        $this->meta->setLastInsertedId($tableName, $this->maxId($records));

        return $issue->withRepaired();
    }

    private function repairMetaOrphanEntry(
        IntegrityIssue $issue,
    ): IntegrityIssue {
        $tableName = (string)$issue->tableName;
        $this->meta->dropEntry($tableName);

        return $issue->withRepaired();
    }

    /**
     * The createTable crash window: the table is registered in the schema
     * but its data file never appeared. Structural repair only — provision
     * an empty data file, missing index files and (when absent) the meta
     * entry, so the table becomes operational again. No data is invented:
     * a lost non-empty file cannot be restored, and the fresh entry states
     * exactly that (0 rows).
     */
    private function repairTableFileMissing(
        IntegrityIssue $issue,
    ): IntegrityIssue {
        $tableName = (string)$issue->tableName;
        $tableSchema = $this->schema->getTable($tableName);

        $this->ndjson->createFileFresh($tableName, $tableSchema->getFileName());

        foreach ($tableSchema->indexes as $index) {
            if (!$this->ndjson->exists($tableName, $index->getFileName())) {
                $this->ndjson->createFileFresh(
                    $tableName,
                    $index->getFileName(),
                );
            }
        }

        if (!$this->meta->hasEntry($tableName)) {
            $this->meta->initTable($tableName);
        } else {
            $this->meta->commitRewrite($tableName, 0, 0);
        }

        return $issue->withRepaired();
    }

    /**
     * Deletes an orphan root entry. A directory is removed as a whole ONLY
     * when none of its files holds any bytes — repair fixes structures,
     * never destroys data; a non-empty orphan requires a manual decision.
     */
    private function repairOrphanDbEntry(IntegrityIssue $issue): IntegrityIssue
    {
        $name = $issue->context['name'] ?? null;
        $kind = $issue->context['kind'] ?? null;

        if ($name === null || $kind === null) {
            return $issue->withRepairError(
                'issue context has no "name"/"kind" keys',
            );
        }

        if ($kind === 'dir') {
            foreach ($this->ndjson->listFiles($name) as $fileName) {
                if ($this->ndjson->fileSizeBytes($name, $fileName) > 0) {
                    return $issue->withRepairError(
                        'non-empty orphan dir requires manual removal',
                    );
                }
            }

            $this->ndjson->deleteTable($name);

            return $issue->withRepaired();
        }

        $this->json->deleteFile($name);

        return $issue->withRepaired();
    }

    /**
     * @param array<int,array<string,null|scalar>> $records
     *
     * @return array<int,array<string,null|scalar>>
     */
    private function normalizeAndSort(
        TableSchema $tableSchema,
        array $records,
    ): array {
        $normalized = array_map(
            fn (array $r): array => $this->normalizeRecord($tableSchema, $r),
            $records,
        );

        usort(
            $normalized,
            static function (array $a, array $b): int {
                $idA = $a[PrimaryKey::FIELD] ?? 0;
                $idB = $b[PrimaryKey::FIELD] ?? 0;
                $intA = \is_int($idA) ? $idA : 0;
                $intB = \is_int($idB) ? $idB : 0;

                return $intA <=> $intB;
            },
        );

        return $normalized;
    }

    /**
     * @param array<string,null|scalar> $record
     *
     * @return array<string,null|scalar>
     */
    private function normalizeRecord(
        TableSchema $tableSchema,
        array $record,
    ): array {
        $normalized = [];

        foreach (array_keys($tableSchema->columns) as $column) {
            $normalized[$column] = $record[$column] ?? null;
        }

        return $normalized;
    }

    /**
     * @param array<int,array<string,null|scalar>> $records
     */
    private function maxId(array $records): int
    {
        $max = 0;

        foreach ($records as $record) {
            $id = $record[PrimaryKey::FIELD] ?? null;

            if (\is_int($id) && $id > $max) {
                $max = $id;
            }
        }

        return $max;
    }
}
