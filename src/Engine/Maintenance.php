<?php

declare(strict_types=1);

namespace AV\JsonProvider\Engine;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\FkBackingPolicyEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Backup\Backup;
use AV\JsonProvider\Services\Backup\Restore;
use AV\JsonProvider\Services\Format\FreshnessEnum;
use AV\JsonProvider\Services\Format\MigrationReport;
use AV\JsonProvider\Services\Format\StorageStatus;
use AV\JsonProvider\Services\Format\TableFreshness;
use AV\JsonProvider\Services\Integrity\IntegrityRepairer;
use AV\JsonProvider\Services\Integrity\IntegrityReport;
use AV\JsonProvider\Services\Integrity\IntegrityValidator;
use AV\JsonProvider\Storage\DerivedFiles;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\StorageManifest;
use AV\JsonProvider\Storage\TableLockManager;
use AV\JsonProvider\Validation\ValueValidator;
use Psr\Log\LoggerInterface;

/**
 * Storage format status and migration, integrity validation and
 * repair, table optimization, backup and restore, with the services
 * that run them built on first use.
 *
 * @internal
 */
final class Maintenance
{
    private IntegrityValidator | null $validator = null;

    private IntegrityRepairer | null $repairer = null;

    private Backup | null $backup = null;

    private Restore | null $restore = null;

    private readonly string $dbPath;
    private readonly DerivedFiles $derived;
    private readonly TableFreshness $freshness;
    private readonly IndexManager $indexManager;
    private readonly JsonStorage $json;
    private readonly TableLockManager $locks;
    private readonly LoggerInterface | null $logger;
    private readonly MetaRegistry $meta;
    private readonly NdjsonStorage $ndjson;
    private readonly SchemaRegistry $schema;
    private readonly ValueValidator $values;

    public function __construct(
        private readonly Context $context,
        private readonly TableStore $store,
    ) {
        $this->dbPath = $context->dbPath;
        $this->derived = $context->derived;
        $this->freshness = $context->freshness;
        $this->indexManager = $context->indexManager;
        $this->json = $context->json;
        $this->locks = $context->locks;
        $this->logger = $context->logger;
        $this->meta = $context->meta;
        $this->ndjson = $context->ndjson;
        $this->schema = $context->schema;
        $this->values = $context->values;
    }

    public function optimizeTable(string $tableName): void
    {
        $this->context->assertWritable();

        $this->locks->withLocks(
            [$tableName => 'ex'],
            'sh',
            function () use ($tableName): void {
                $this->repairer()->optimizeTable($tableName);
                $this->store->invalidateCache($tableName);
            },
        );
    }

    public function validateTable(string $tableName): IntegrityReport
    {
        return $this->validator()->validateTable($tableName);
    }

    public function validate(): IntegrityReport
    {
        return $this->validator()->validateDatabase();
    }

    public function repairTable(string $tableName): IntegrityReport
    {
        $this->context->assertWritable();

        return $this->locks->withLocks(
            [$tableName => 'ex'],
            'sh',
            function () use ($tableName): IntegrityReport {
                $report = $this->repairer()->repairTable($tableName);
                $this->store->invalidateCache($tableName);

                return $report;
            },
        );
    }

    public function repair(): IntegrityReport
    {
        $this->context->assertWritable();

        return $this->locks->withDatabase(
            function (): IntegrityReport {
                $this->schema->reload();
                $plan = array_fill_keys(
                    array_keys($this->schema->getTables()),
                    'ex',
                );

                return $this->locks->withLocks(
                    $plan,
                    null,
                    function (): IntegrityReport {
                        $report = $this->repairer()->repairDatabase();

                        foreach (
                            array_keys($this->schema->getTables()) as $table
                        ) {
                            $this->store->invalidateCache($table);
                        }

                        return $report;
                    },
                );
            },
        );
    }

    public function backup(string $destination): string
    {
        return $this->backupService()->export($destination);
    }

    public function restore(
        string $archivePath,
        bool $adoptArchivedSchema = false,
        bool $pruneExtraTables = false,
    ): void {
        $this->context->assertWritable();

        foreach (array_keys($this->schema->getTables()) as $table) {
            $this->store->invalidateCache($table);
        }

        $this->restoreService()->restore(
            $archivePath,
            $adoptArchivedSchema,
            $pruneExtraTables,
        );
        $this->schema->reload();

        foreach (array_keys($this->schema->getTables()) as $table) {
            $this->store->invalidateCache($table);
        }

        $this->migrateStorage();
    }

    public function storageStatus(): StorageStatus
    {
        $manifest = StorageManifest::read($this->dbPath);
        $pendingTables = [];

        foreach ($this->schema->getTables() as $name => $tableSchema) {
            if (
                $manifest->isLegacy()
                || $this->freshness->verdict($tableSchema, true)
                !== FreshnessEnum::FRESH
                || !$this->derivedCurrent($tableSchema)
            ) {
                $pendingTables[] = $name;
            }
        }

        $pendingSteps = [];

        for (
            $generation = $manifest->generation;
            $generation < StorageManifest::GENERATION;
            $generation++
        ) {
            $pendingSteps[] = self::stepName($generation);
        }

        return new StorageStatus(
            generation: $manifest->generation,
            engineGeneration: StorageManifest::GENERATION,
            compat: $manifest->compat,
            roCompat: $manifest->roCompat,
            incompat: $manifest->incompat,
            readOnly: $manifest->unknownRoCompat() !== [],
            pendingSteps: $pendingSteps,
            pendingTables: $pendingTables,
            pendingFeatures: $manifest->isLegacy()
                ? []
                : $manifest->missingFeatures(),
        );
    }

    public function migrateStorage(
        int | null $toGeneration = null,
    ): MigrationReport {
        $this->context->assertWritable();
        $target = $toGeneration ?? StorageManifest::GENERATION;

        $report = $this->locks->withDatabase(
            function () use ($target): MigrationReport {
                $this->schema->reload();
                $plan = array_fill_keys(
                    array_keys($this->schema->getTables()),
                    'ex',
                );

                return $this->locks->withLocks(
                    $plan,
                    null,
                    fn (): MigrationReport => $this->migrateLocked($target),
                );
            },
        );

        foreach (array_keys($this->schema->getTables()) as $table) {
            $this->store->invalidateCache($table);
        }

        return $report;
    }

    /**
     * The body of migrateStorage(), under the database and table locks.
     */
    private function migrateLocked(int $target): MigrationReport
    {
        $from = StorageManifest::read($this->dbPath)->generation;

        if ($target < $from || $target > StorageManifest::GENERATION) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::StorageMigrationTargetInvalid,
                (string)$target,
                (string)$from,
                (string)StorageManifest::GENERATION,
            );
        }

        $this->context->migrating = true;
        $steps = [];
        $refreshed = [];
        $skipped = [];

        try {
            for ($generation = $from; $generation < $target; $generation++) {
                $this->migrateStep($generation, $refreshed, $skipped);
                $steps[] = self::stepName($generation);
            }

            if ($steps === [] && $from >= StorageManifest::GENERATION) {
                $this->refreshAllTables($refreshed, $skipped);
                $manifest = StorageManifest::read($this->dbPath);

                if ($manifest->missingFeatures() !== []) {
                    $manifest->withFeatures()->write($this->dbPath);
                }
            }
        } finally {
            $this->context->migrating = false;
        }

        $this->context->freshnessNoted = [];

        return new MigrationReport(
            fromGeneration: $from,
            toGeneration: max($from, $target),
            steps: $steps,
            refreshedTables: $refreshed,
            skippedTables: $skipped,
        );
    }

    /**
     * Runs the one step leading from $generation to the next.
     *
     * @param list<string> $refreshed
     * @param list<string> $skipped
     */
    private function migrateStep(
        int $generation,
        array &$refreshed,
        array &$skipped,
    ): void {
        if ($generation === 1) {
            $this->migrateGeneration1To2($refreshed, $skipped);

            return;
        }

        throw new JsonProviderServiceException(
            JsonProviderErrorEn::StorageMigrationTargetInvalid,
            (string)($generation + 1),
            (string)$generation,
            (string)StorageManifest::GENERATION,
        );
    }

    /**
     * Generation 1 -> 2: every table gets its indexes rebuilt from the data
     * (never taken on faith — a stale index would otherwise be stamped as
     * fresh) and the inode stamp; the manifest comes last.
     *
     * @param list<string> $refreshed
     * @param list<string> $skipped
     */
    private function migrateGeneration1To2(
        array &$refreshed,
        array &$skipped,
    ): void {
        $this->context->enableGeneration2();
        $this->refreshAllTables($refreshed, $skipped);
        StorageManifest::current()->write($this->dbPath);
    }

    /**
     * @param list<string> $refreshed
     * @param list<string> $skipped
     */
    private function refreshAllTables(array &$refreshed, array &$skipped): void
    {
        foreach ($this->schema->getTables() as $name => $tableSchema) {
            $result = $this->bringTableCurrent($tableSchema);

            if ($result === true) {
                $refreshed[] = $name;
            } elseif ($result === false) {
                $skipped[] = $name;
            }
        }
    }

    /**
     * Makes one table fresh. Drifted counters are healed by the canonical
     * full rewrite; a stale or missing stamp only needs the indexes
     * rebuilt from the data and stamped — the data file stays as it is. A
     * table holding a line that is not a record is left alone: a rewrite
     * would drop that line, and indexes built around it cannot be
     * certified. Returns null when the table was already fresh, false when
     * it was left alone.
     */
    private function bringTableCurrent(TableSchema $tableSchema): bool | null
    {
        $freshness = $this->freshness->check($tableSchema);

        if ($freshness === FreshnessEnum::FRESH) {
            if ($this->derivedCurrent($tableSchema)) {
                return null;
            }

            $this->rebuildDerived($tableSchema);

            return true;
        }

        if ($this->freshness->holdsBrokenRecords($tableSchema)) {
            return false;
        }

        if ($freshness === FreshnessEnum::DRIFT) {
            $this->store->ensureTableConsistent($tableSchema);

            return true;
        }

        return $this->freshness->refresh($tableSchema);
    }

    private static function stepName(int $generation): string
    {
        return $generation . '->' . ($generation + 1);
    }

    /**
     * Whether the table's derived lookup files describe its files as they
     * are now.
     */
    private function derivedCurrent(TableSchema $tableSchema): bool
    {
        try {
            $lineCount = $this->meta->getLineCount($tableSchema->name);
        } catch (JsonProviderException) {
            return false;
        }

        $indexFiles = [];

        foreach ($tableSchema->indexes as $index) {
            $indexFiles[$index->getFileName()] = $this->ndjson->pathOf(
                $tableSchema->name,
                $index->getFileName(),
            );
        }

        return $this->derived->tableCurrent(
            $tableSchema->name,
            $this->context->dataPath($tableSchema->name),
            $lineCount,
            $indexFiles,
        );
    }

    /**
     * Builds the derived lookup files of a fresh table: the line offsets
     * from the data file, a sorted head for every index file (rewriting it
     * sorted when no head is recorded).
     */
    private function rebuildDerived(TableSchema $tableSchema): void
    {
        $lineCount = $this->meta->getLineCount($tableSchema->name);
        $this->derived->rebuildLineOffsets(
            $tableSchema->name,
            $this->context->dataPath($tableSchema->name),
        );

        foreach ($tableSchema->indexes as $index) {
            $this->indexManager->mergeTail(
                $tableSchema->name,
                $index,
                $lineCount,
                $lineCount,
            );
        }
    }

    private function validator(): IntegrityValidator
    {
        if ($this->validator === null) {
            $this->validator = new IntegrityValidator(
                $this->schema,
                $this->meta,
                $this->ndjson,
                $this->json,
                $this->indexManager,
                $this->values,
                $this->freshness,
                $this->logger,
            );
        }

        return $this->validator;
    }

    private function repairer(): IntegrityRepairer
    {
        if ($this->repairer === null) {
            $this->repairer = new IntegrityRepairer(
                $this->validator(),
                $this->schema,
                $this->meta,
                $this->ndjson,
                $this->json,
                $this->indexManager,
                $this->values,
                $this->freshness,
                fn (): FkBackingPolicyEnum => $this->context->fkBackingPolicy,
            );
        }

        return $this->repairer;
    }

    private function backupService(): Backup
    {
        if ($this->backup === null) {
            $this->backup = new Backup(
                $this->dbPath,
                $this->schema,
                $this->json,
                $this->ndjson,
                $this->meta,
                $this->locks,
                $this->validator(),
            );
        }

        return $this->backup;
    }

    private function restoreService(): Restore
    {
        if ($this->restore === null) {
            $this->restore = new Restore(
                $this->backupService(),
                $this->schema,
                $this->meta,
                $this->ndjson,
                $this->indexManager,
                $this->values,
                $this->locks,
                $this->logger,
            );
        }

        return $this->restore;
    }
}
