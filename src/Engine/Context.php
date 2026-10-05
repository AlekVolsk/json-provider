<?php

declare(strict_types=1);

namespace AV\JsonProvider\Engine;

use AV\JsonProvider\Cache\CacheInterface;
use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Index\IndexManager;
use AV\JsonProvider\Mapping\DtoMapper;
use AV\JsonProvider\Mapping\DtoRegistry;
use AV\JsonProvider\Query\ComparisonModeEnum;
use AV\JsonProvider\Registry\MetaRegistry;
use AV\JsonProvider\Registry\SchemaRegistry;
use AV\JsonProvider\Schema\FkBackingPolicyEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Services\Format\TableFreshness;
use AV\JsonProvider\Storage\BrokenRecordPolicyEnum;
use AV\JsonProvider\Storage\DerivedFiles;
use AV\JsonProvider\Storage\JsonStorage;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Storage\StorageManifest;
use AV\JsonProvider\Storage\TableLockManager;
use AV\JsonProvider\Validation\ValueValidator;
use Psr\Log\LoggerInterface;

/**
 * Shared state of one provider instance: the storage, registry, lock,
 * cache and value collaborators every engine part works with, and the
 * settings the provider's setters change. Settings are read at the moment
 * of use, so a setter takes effect on the next call. Building the context
 * opens the storage format of the database: a format this engine cannot
 * open is refused, and unknown read-only features block every write.
 *
 * @internal
 */
final class Context
{
    public const string SCHEMA_FILE = 'information_schema.json';

    private const string LEGACY_FORMAT_NOTICE = 'database "%s" is stored in '
        . 'storage format generation 1 (written by json-provider 1.0): it '
        . 'works as before, without the protections of generation %d; '
        . 'migrate it with migrateStorage() to switch them on';

    private const string READ_ONLY_NOTICE = 'database is open read-only: it '
        . 'uses storage features this engine cannot write safely: %s';

    public readonly TableLockManager $locks;
    public readonly NdjsonStorage $ndjson;
    public readonly JsonStorage $json;
    public readonly SchemaRegistry $schema;
    public readonly IndexManager $indexManager;
    public readonly MetaRegistry $meta;
    public readonly ValueValidator $values;
    public readonly DtoRegistry $dtoRegistry;
    public readonly DtoMapper $dtoMapper;
    public readonly TableFreshness $freshness;
    public readonly DerivedFiles $derived;

    public ComparisonModeEnum $comparisonMode = ComparisonModeEnum::Binary;
    public BrokenRecordPolicyEnum $brokenRecordPolicy
        = BrokenRecordPolicyEnum::Drop;
    public FkBackingPolicyEnum $fkBackingPolicy
        = FkBackingPolicyEnum::SingleColumn;

    /**
     * True while a storage migration runs: it rewrites or re-stamps the
     * very tables the per-table notices would report.
     */
    public bool $migrating = false;

    /**
     * Tables already reported as unstamped or stale by this instance:
     * each is reported once per process (once per request under FPM).
     *
     * @var array<string,true>
     */
    public array $freshnessNoted = [];

    /**
     * roCompat features of the storage format this engine does not know;
     * non-empty means every write is refused.
     *
     * @var list<string>
     */
    public readonly array $readOnlyFeatures;

    public function __construct(
        public readonly string $dbPath,
        public readonly string $cacheNs,
        public readonly CacheInterface $cache,
        public readonly LoggerInterface | null $logger,
    ) {
        $this->locks = new TableLockManager($dbPath);
        $this->ndjson = new NdjsonStorage($dbPath, $this->locks);
        $this->json = new JsonStorage($dbPath, $this->locks);
        $this->schema = new SchemaRegistry($this->json);
        $this->derived = new DerivedFiles($dbPath);
        $this->indexManager = new IndexManager($this->ndjson, $this->derived);
        $this->meta = new MetaRegistry($this->json);
        $this->values = new ValueValidator();
        $this->dtoRegistry = new DtoRegistry();
        $this->dtoMapper = new DtoMapper();
        $this->freshness = new TableFreshness(
            $this->meta,
            $this->ndjson,
            $this->indexManager,
            $this->values,
        );
        $this->readOnlyFeatures = $this->openStorageFormat();
    }

    /**
     * Strips trailing slashes so every spelling of a directory maps to one
     * singleton (and one lock manager). The filesystem root stays "/".
     */
    public static function normalizePath(string $dbPath): string
    {
        $normalized = rtrim(str_replace('\\', '/', $dbPath), '/');

        return $normalized === '' ? '/' : $normalized;
    }

    /**
     * A warning goes to the PSR-3 logger when one is given; without it,
     * to the PHP error log as a user deprecation (the format notice) or a
     * user warning (read-only mode), so it is never silently lost.
     */
    public function warn(string $message, bool $severe): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);

            return;
        }

        trigger_error($message, $severe ? E_USER_WARNING : E_USER_DEPRECATED);
    }

    /**
     * Refuses any write while the storage format carries a roCompat
     * feature this engine does not know.
     */
    public function assertWritable(): void
    {
        if ($this->readOnlyFeatures === []) {
            return;
        }

        throw new JsonProviderServiceException(
            JsonProviderErrorEn::StorageReadOnly,
            StorageManifest::describe($this->readOnlyFeatures, $this->dbPath),
        );
    }

    public function dataPath(string $table): string
    {
        return $this->ndjson->pathOf($table, TableSchema::dataFileName($table));
    }

    /**
     * Turns on what a generation-2 database maintains: the table stamps and
     * the derived lookup files, which follow every committed rewrite and
     * append.
     */
    public function enableGeneration2(): void
    {
        $this->freshness->enableStamps();
        $this->derived->enable();
        $this->derived->prepare();
        $this->meta->onCommit(
            function (string $table): void {
                $this->derived->rebuildLineOffsets(
                    $table,
                    $this->dataPath($table),
                );
            },
            function (string $table, int $lineCount, int $byteSize): void {
                $this->derived->appendLineOffset(
                    $table,
                    $this->dataPath($table),
                    $lineCount,
                    $byteSize,
                );
            },
        );
    }

    /**
     * Reads the storage format of the database once per instance — once
     * per request under PHP-FPM. Refuses a format this engine cannot open,
     * warns about a generation-1 database (it keeps working exactly as
     * under 1.0) and about read-only mode. A path that holds no database
     * yet is left alone.
     *
     * @return list<string> the roCompat features this engine does not know
     */
    private function openStorageFormat(): array
    {
        if (!$this->json->exists(self::SCHEMA_FILE)) {
            return [];
        }

        $manifest = StorageManifest::read($this->dbPath);
        $manifest->assertOpenable($this->dbPath);

        if ($manifest->isLegacy()) {
            $this->warn(
                \sprintf(
                    self::LEGACY_FORMAT_NOTICE,
                    $this->dbPath,
                    StorageManifest::GENERATION,
                ),
                false,
            );

            return [];
        }

        $this->enableGeneration2();
        $readOnly = $manifest->unknownRoCompat();

        if ($readOnly !== []) {
            $this->warn(
                \sprintf(
                    self::READ_ONLY_NOTICE,
                    StorageManifest::describe($readOnly, $this->dbPath),
                ),
                true,
            );
        }

        return $readOnly;
    }
}
