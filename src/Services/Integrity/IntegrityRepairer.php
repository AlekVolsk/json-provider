<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Integrity;

use AV\JsonProvider\Exception\JsonProviderDataException;
use AV\JsonProvider\Exception\JsonProviderTableException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\ColumnDefaults;
use AV\JsonProvider\Schema\IdentifierRules;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\PrimaryKey;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Validation\ValueValidator;

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
 * Report-only categories (BROKEN_RECORD, PRESENT_NULL, FK_ORPHAN,
 * UNIQUE_DUPLICATE) are returned untouched: repair fixes STRUCTURES, not
 * data — it never quarantines, rewrites or deletes user records to make a
 * finding go away.
 *
 * After per-issue repair, an explicit "table optimize" pass is run for every
 * touched table (sort records by id ASC + rebuild every index), recorded as
 * a TABLE_OPTIMIZED info issue per table. The pass refuses tables holding
 * unparseable lines (the rewrite would silently drop them).
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
        private readonly ValueValidator $values,
    ) {
    }

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
        $issues = [];

        /*
         * The pending-rename reconciliation runs BEFORE validation:
         * per-issue repair would otherwise see the half-renamed table as
         * TABLE_FILE_MISSING and provision fresh empty files under the
         * new name, blocking the roll-forward of the real data.
         */
        try {
            $renameIssue = $this->reconcilePendingRename();

            if ($renameIssue !== null) {
                $issues[] = $renameIssue;
            }
        } catch (\Throwable $e) {
            $issues[] = new IntegrityIssue(
                IssueSeverity::ERROR,
                IssueCategory::RENAME_INCOMPLETE,
                null,
                'pending rename reconciliation failed',
                repairError: $e->getMessage(),
            );
        }

        $report = $this->validator->validateDatabase();
        $issues = array_merge($issues, $this->repairIssues($report->issues));

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
     * inside repair operations. Float columns are widened before the
     * rewrite, so the pass also migrates legacy int-encoded float values
     * ("price":99) into the canonical zero-fraction form ("price":99.0).
     *
     * DATA PROTECTION: refuses to rewrite a file holding unparseable
     * lines — a read()+write() roundtrip would silently drop them; they
     * must be resolved manually first (validate() lists each one as a
     * broken_record finding).
     *
     * Returns an INFO issue describing the result (TABLE_OPTIMIZED), or
     * an ERROR REPAIR_FAILED issue when the broken-line guard refuses.
     */
    public function optimizeTable(string $tableName): IntegrityIssue
    {
        $tableSchema = $this->schema->getTable($tableName);
        $rawLines = $this->ndjson->readRawLines(
            $tableName,
            $tableSchema->getFileName(),
        );

        if ($rawLines['broken'] !== []) {
            return new IntegrityIssue(
                IssueSeverity::ERROR,
                IssueCategory::REPAIR_FAILED,
                $tableName,
                'table has ' . \count($rawLines['broken'])
                    . ' unparseable line(s); resolve manually before '
                    . 'optimize',
            );
        }

        $records = $this->values->widenFloats(
            $tableSchema,
            $rawLines['records'],
        );

        $records = $this->normalizeAndSort($tableSchema, $records);

        $byteSize = $this->ndjson->write(
            $tableName,
            $tableSchema->getFileName(),
            $records,
        );
        $this->indexManager->rebuild($tableSchema, $records);
        $this->meta->commitRewrite($tableName, \count($records), $byteSize);

        if ($this->meta->getIndexFormat($tableName) < 2) {
            $this->meta->stampIndexFormat($tableName, 2);
        }

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
                IssueCategory::INDEX_UNRELIABLE,
                IssueCategory::INDEX_DRIFT => $this
                    ->repairIndex($issue),
                IssueCategory::INDEX_FORMAT_OUTDATED => $this
                    ->repairIndexFormat($issue),
                IssueCategory::ORPHAN_INDEX_FILE => $this
                    ->repairOrphanIndexFile($issue),
                IssueCategory::RECORD_KEY_ORDER => $this
                    ->repairRecordKeyOrder($issue),
                IssueCategory::META_ENTRY_MISSING => $this
                    ->repairMetaEntryMissing($issue),
                IssueCategory::META_ENTRY_CORRUPT => $this
                    ->repairMetaEntryCorrupt($issue),
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
                IssueCategory::PK_DUPLICATE => $issue->withRepairError(
                    'duplicate primary keys require manual resolution',
                ),
                IssueCategory::BROKEN_RECORD,
                IssueCategory::PRESENT_NULL,
                IssueCategory::FK_ORPHAN,
                IssueCategory::UNIQUE_DUPLICATE  => $issue,
                IssueCategory::RENAME_INCOMPLETE => $issue->withRepairError(
                    'a pending rename spans two tables and the meta file; '
                        . 'run the database-level repair() to reconcile it',
                ),
                IssueCategory::FK_BACKING_INDEX_MISSING => $this
                    ->repairFkBackingIndex($issue),
                IssueCategory::FK_BACKING_INDEX_ORPHANED => $this
                    ->repairOrphanedServiceIndex($issue),
                default => $issue,
            };
        } catch (\Throwable $e) {
            return $issue->withRepairError($e->getMessage());
        }
    }

    /**
     * Removes a service index no probing relation points to: the schema
     * entry first, then the file (a crash in between leaves an orphan
     * *.index.ndjson swept by the orphan repair). Re-verifies the
     * orphanhood against the fresh schema before touching anything.
     */
    private function repairOrphanedServiceIndex(
        IntegrityIssue $issue,
    ): IntegrityIssue {
        $tableName = (string)$issue->tableName;
        $indexName = $issue->context['index'] ?? null;

        if ($indexName === null) {
            return $issue->withRepairError('issue context has no "index" key');
        }

        foreach ($this->schema->getAllRelations() as $relation) {
            if (
                $relation->childTable() === $tableName
                && $relation->needsBackingIndex()
                && $relation->backingIndex === $indexName
            ) {
                return $issue->withRepairError(
                    'a probing relation references the index now',
                );
            }
        }

        $found = null;

        foreach ($this->schema->getTable($tableName)->indexes as $index) {
            if ($index->name === $indexName && $index->isService) {
                $found = $index;

                break;
            }
        }

        if ($found === null) {
            return $issue->withRepaired();
        }

        $this->schema->mutate(
            static function (
                array $tables,
                array $relations,
            ) use (
                $tableName,
                $indexName,
            ): array {
                $table = $tables[$tableName]
                    ?? throw new JsonProviderTableException(
                        JsonProviderErrorEn::TableNotFound,
                        $tableName,
                    );

                $tables[$tableName] = new TableSchema(
                    name: $table->name,
                    uniqueConstraints: $table->uniqueConstraints,
                    columns: $table->columns,
                    indexes: array_values(array_filter(
                        $table->indexes,
                        static fn (IndexSchema $i): bool => $i
                            ->name !== $indexName,
                    )),
                    tableComment: $table->tableComment,
                    columnComment: $table->columnComment,
                );

                return [$tables, $relations];
            },
        );
        $this->ndjson->deleteFile($tableName, $found->getFileName());

        return $issue->withRepaired();
    }

    /**
     * Structural FK repair: builds the service index "_fk_<column>" on
     * the child table from the current data and points the relation's
     * backingIndex at it in one schema RMW. A covering single-column USER
     * index on the FK column is reused instead (mirroring the addRelation
     * provisioning), so repair never plants a duplicate index. File
     * first, schema second — a crash in between leaves an orphan
     * *.index.ndjson swept by the orphan repair. Data is never touched.
     */
    private function repairFkBackingIndex(
        IntegrityIssue $issue,
    ): IntegrityIssue {
        $tableName = (string)$issue->tableName;
        $from = $issue->context['from'] ?? null;
        $foreignKey = $issue->context['foreignKey'] ?? null;
        $to = $issue->context['to'] ?? null;
        $column = $issue->context['column'] ?? null;

        if (
            $from === null
            || $foreignKey === null
            || $to === null
            || $column === null
        ) {
            return $issue->withRepairError(
                'issue context lacks the relation address',
            );
        }

        $tableSchema = $this->schema->getTable($tableName);

        if (!\array_key_exists($column, $tableSchema->columns)) {
            return $issue->withRepairError(
                'FK column "' . $column . '" does not exist in table "'
                    . $tableName . '" — fix the relation declaration first',
            );
        }

        $backing = null;

        foreach ($tableSchema->indexes as $index) {
            if (
                !$index->isService
                && !$index->isPrimary
                && \count($index->fields) === 1
                && $index->fields[0]->field === $column
            ) {
                $backing = $index;

                break;
            }
        }

        if ($backing === null) {
            $backing = new IndexSchema(
                name: IdentifierRules::serviceIndexNameFor($column),
                fields: [
                    new IndexFieldSchema($column, SortDirectionEnum::ASC),
                ],
                isService: true,
            );

            $this->ndjson->createFileFresh(
                $tableName,
                $backing->getFileName(),
            );
            $records = $this->values->widenFloats(
                $tableSchema,
                $this->ndjson->read($tableName, $tableSchema->getFileName()),
            );

            if ($this->meta->getIndexFormat($tableName) < 2) {
                $this->rebuildAllAndStamp($tableSchema);
            }

            $this->indexManager->rebuildOne($tableName, $backing, $records);
        }

        $this->schema->mutate(
            static function (
                array $tables,
                array $relations,
            ) use (
                $tableName,
                $from,
                $foreignKey,
                $to,
                $backing,
            ): array {
                $table = $tables[$tableName]
                    ?? throw new JsonProviderTableException(
                        JsonProviderErrorEn::TableNotFound,
                        $tableName,
                    );

                if ($backing->isService) {
                    $indexes = array_values(array_filter(
                        $table->indexes,
                        static fn (IndexSchema $i): bool => $i
                            ->name !== $backing->name,
                    ));
                    $indexes[] = $backing;

                    $tables[$tableName] = new TableSchema(
                        name: $table->name,
                        uniqueConstraints: $table->uniqueConstraints,
                        columns: $table->columns,
                        indexes: $indexes,
                        tableComment: $table->tableComment,
                        columnComment: $table->columnComment,
                    );
                }

                $relations = array_map(
                    static function (
                        RelationSchema $r,
                    ) use (
                        $from,
                        $foreignKey,
                        $to,
                        $backing,
                    ): RelationSchema {
                        $matches = $r->fromTable === $from
                            && $r->foreignKey === $foreignKey
                            && $r->toTable === $to;

                        return $matches
                            ? $r->withBackingIndex($backing->name)
                            : $r;
                    },
                    $relations,
                );

                return [$tables, $relations];
            },
        );

        return $issue->withRepaired();
    }

    private function repairIndex(IntegrityIssue $issue): IntegrityIssue
    {
        $tableName = (string)$issue->tableName;
        $indexName = $issue->context['index'] ?? null;

        if ($indexName === null) {
            return $issue->withRepairError('issue context has no "index" key');
        }

        $tableSchema = $this->schema->getTable($tableName);

        if ($this->meta->getIndexFormat($tableName) < 2) {
            $this->rebuildAllAndStamp($tableSchema);

            return $issue->withRepaired();
        }

        $indexSchema = $this->indexManager->findIndex($tableSchema, $indexName);

        if (!$this->ndjson->exists($tableName, $indexSchema->getFileName())) {
            $this->ndjson->createFile($tableName, $indexSchema->getFileName());
        }

        $records = $this->values->widenFloats(
            $tableSchema,
            $this->ndjson->read($tableName, $tableSchema->getFileName()),
        );
        $this->indexManager->rebuildOne($tableName, $indexSchema, $records);

        return $issue->withRepaired();
    }

    /**
     * Pre-v2 index format: every index of the table is rebuilt with the
     * current encoder in one pass, then the format is stamped. Rebuilding
     * a single file would leave the rest v1 under a v2 marker.
     */
    private function repairIndexFormat(IntegrityIssue $issue): IntegrityIssue
    {
        $tableName = (string)$issue->tableName;

        $this->rebuildAllAndStamp($this->schema->getTable($tableName));

        return $issue->withRepaired();
    }

    private function rebuildAllAndStamp(TableSchema $tableSchema): void
    {
        $records = $this->values->widenFloats(
            $tableSchema,
            $this->ndjson->read(
                $tableSchema->name,
                $tableSchema->getFileName(),
            ),
        );

        $this->indexManager->rebuild($tableSchema, $records);

        if ($this->meta->getIndexFormat($tableSchema->name) < 2) {
            $this->meta->stampIndexFormat($tableSchema->name, 2);
        }
    }

    /**
     * Deletes an orphan file from the table subdirectory. Only files that
     * look like index files ("<name>.index.ndjson") or hold no bytes are
     * removed: a non-empty file with any other name may be stranded DATA
     * (e.g. the old data file of a crashed renameTable) — repair fixes
     * structures, never destroys data, so it is left for a manual (or
     * reconcile) decision.
     */
    private function repairOrphanIndexFile(
        IntegrityIssue $issue,
    ): IntegrityIssue {
        $tableName = (string)$issue->tableName;
        $fileName = $issue->context['file'] ?? null;

        if ($fileName === null) {
            return $issue->withRepairError('issue context has no "file" key');
        }

        if (
            !str_ends_with($fileName, '.index.ndjson')
            && $this->ndjson->exists($tableName, $fileName)
            && $this->ndjson->fileSizeBytes($tableName, $fileName) > 0
        ) {
            return $issue->withRepairError(
                'non-empty orphan file is not an index file and may hold '
                    . 'data; requires manual removal',
            );
        }

        $this->ndjson->deleteFile($tableName, $fileName);

        return $issue->withRepaired();
    }

    /**
     * Rewrites the table with schema-ordered record keys. The same
     * broken-line guard as optimizeTable protects unparseable lines from
     * being silently dropped by the read+write roundtrip.
     */
    private function repairRecordKeyOrder(IntegrityIssue $issue): IntegrityIssue
    {
        $tableName = (string)$issue->tableName;
        $tableSchema = $this->schema->getTable($tableName);
        $rawLines = $this->ndjson->readRawLines(
            $tableName,
            $tableSchema->getFileName(),
        );

        if ($rawLines['broken'] !== []) {
            return $issue->withRepairError(
                'table has ' . \count($rawLines['broken'])
                    . ' unparseable line(s); resolve manually before '
                    . 'the key-order rewrite',
            );
        }

        $records = $this->values->widenFloats(
            $tableSchema,
            $rawLines['records'],
        );
        $records = array_map(
            fn (array $r): array => $this->normalizeRecord($tableSchema, $r),
            $records,
        );

        $this->ndjson->write($tableName, $tableSchema->getFileName(), $records);
        $this->indexManager->rebuild($tableSchema, $records);

        if ($this->meta->getIndexFormat($tableName) < 2) {
            $this->meta->stampIndexFormat($tableName, 2);
        }

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

    /**
     * A corrupt entry is repaired like a missing one — every counter is
     * derivable from the data — after dropping the broken record first
     * (initTable refuses to overwrite an existing entry).
     */
    private function repairMetaEntryCorrupt(
        IntegrityIssue $issue,
    ): IntegrityIssue {
        $this->meta->dropEntry((string)$issue->tableName);

        return $this->repairMetaEntryMissing($issue);
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

        /*
         * A pending rename involving this table means the "missing" data
         * file is really the stranded old-name file of a crashed
         * renameTable: provisioning an empty file here would block the
         * roll-forward. The reconcile pass owns that state.
         */
        $pending = $this->meta->getPendingRename();

        if (
            $pending !== null
            && ($pending['from'] === $tableName
                || $pending['to'] === $tableName)
        ) {
            return $issue->withRepairError(
                'a pending rename involves this table; run the '
                    . 'database-level repair() to reconcile it first',
            );
        }

        /*
         * Re-verify before the destructive step: the file may have been
         * provisioned by an earlier repair action in this same run, and
         * createFileFresh would EMPTY an existing file.
         */
        if (!$this->ndjson->exists($tableName, $tableSchema->getFileName())) {
            $this->ndjson->createFileFresh(
                $tableName,
                $tableSchema->getFileName(),
            );
        }

        foreach ($tableSchema->indexes as $index) {
            if (!$this->ndjson->exists($tableName, $index->getFileName())) {
                $this->ndjson->createFileFresh(
                    $tableName,
                    $index->getFileName(),
                );
            }
        }

        $tail = $this->ndjson->repairTail(
            $tableName,
            $tableSchema->getFileName(),
        );
        $records = $this->ndjson->read($tableName, $tableSchema->getFileName());

        if (!$this->meta->hasEntry($tableName)) {
            $this->meta->initTable($tableName);
            $this->meta->setLastInsertedId($tableName, $this->maxId($records));
        }

        $this->meta->commitRewrite(
            $tableName,
            \count($records),
            $tail['size'],
        );

        return $issue->withRepaired();
    }

    /**
     * Reconciles a leftover renameTable marker deterministically by the
     * ACTUAL schema state (the marker is only the trigger for the
     * re-check). Schema already holds the new name — the meta entry and
     * the filesystem are rolled forward under it (directory rename, then
     * data file rename inside, both idempotent); schema still holds the
     * old name — the rename never committed, the filesystem was never
     * touched and only the marker is dropped. Returns null when no marker
     * is present.
     */
    private function reconcilePendingRename(): IntegrityIssue | null
    {
        $pending = $this->meta->getPendingRename();

        if ($pending === null) {
            return null;
        }

        $from = $pending['from'];
        $to = $pending['to'];

        $issue = new IntegrityIssue(
            IssueSeverity::ERROR,
            IssueCategory::RENAME_INCOMPLETE,
            $from,
            'renameTable "' . $from . '" -> "' . $to
                . '" did not complete; reconciled by the actual '
                . 'schema state',
            context: ['from' => $from, 'to' => $to],
        );

        $tables = $this->schema->getTables();

        if (isset($tables[$to])) {
            $this->ndjson->renameTableDir($from, $to);
            $this->ndjson->renameFile(
                $to,
                $from . '.ndjson',
                $to . '.ndjson',
            );
            $this->meta->moveEntry($from, $to);
        }

        $this->meta->clearPendingRename();

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
     * Normalizes a stored record to the schema column set and order. A key
     * PRESENT in the record keeps its value verbatim (including a present
     * null); a key ABSENT from the record is back-filled with the column
     * type's default via ColumnDefaults::forType — the same value
     * migrateColumns would have written — so repairing a crashed migration
     * converges to the migration's target state instead of planting null
     * into a not-null column. A missing not-null column WITHOUT a safe
     * default (temporal, year/month/day) cannot be invented: the throw
     * surfaces as a repairError on the enclosing repair action instead of
     * "healing" the record into a state typed reads reject.
     *
     * @param array<string,null|scalar> $record
     *
     * @return array<string,null|scalar>
     */
    private function normalizeRecord(
        TableSchema $tableSchema,
        array $record,
    ): array {
        $normalized = [];

        foreach ($tableSchema->columns as $column => $type) {
            if (\array_key_exists($column, $record)) {
                $normalized[$column] = $record[$column];

                continue;
            }

            if (!ColumnDefaults::hasSafeDefault($type)) {
                throw new JsonProviderDataException(
                    JsonProviderErrorEn::RecordColumnNoDefaultManual,
                    $tableSchema->name,
                    $column,
                    $type,
                );
            }

            $normalized[$column] = ColumnDefaults::forType($type);
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
