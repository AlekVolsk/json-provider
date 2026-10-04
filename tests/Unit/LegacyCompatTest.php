<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Services\Integrity\IntegrityReport;
use AV\JsonProvider\Storage\StorageManifest;
use AV\JsonProvider\Tests\Support\CompatFixture;
use AV\JsonProvider\Tests\Support\EngineProcess;
use AV\JsonProvider\Tests\Support\SpyLogger;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Compatibility with the published v1.0.0 engine on one database.
 *
 * Consumers upgrade in place and roll back the same way, and under PHP-FPM
 * the old and the new code serve requests side by side while a deploy
 * rolls out. Every file the current engine writes must therefore stay
 * readable and writable by v1.0.0, and everything v1.0.0 writes must be
 * accepted here — in both directions, for data, maintenance, backups and
 * schema changes, and for the two engines writing at the same time.
 *
 * The legacy engine runs in a child process (see EngineProcess); the
 * current one runs in this process unless a test needs it concurrent.
 */
final class LegacyCompatTest
{
    private string $root;

    private string $dbDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-legacy-compat');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * The guard of the whole suite: a harness that silently ran the
     * current sources on both sides would prove nothing.
     */
    #[Test]
    public function legacySideRunsTheExtractedSources(): void
    {
        [$legacy] = EngineProcess::legacy($this->dbDir, [['op' => 'engine']]);
        [$current] = EngineProcess::current(
            $this->dbDir,
            [['op' => 'engine']],
        );

        Assert::same(
            $legacy,
            EngineProcess::legacySourceDir() . '/JsonDataProvider.php',
        );
        Assert::same(
            $current,
            \dirname(__DIR__, 2) . '/src/JsonDataProvider.php',
        );
    }

    #[Test]
    public function legacyEngineServesCurrentDatabase(): void
    {
        $db = $this->currentDatabase();

        $results = EngineProcess::legacy($this->dbDir, [
            [
                'op'     => 'insert',
                'table'  => CompatFixture::OWNERS,
                'record' => ['email' => 'dan@example.test', 'name' => 'dan'],
            ],
            [
                'op'     => 'insert',
                'table'  => CompatFixture::ITEMS,
                'record' => ['ownerId' => 4, 'title' => 'dan-1', 'qty' => 7],
            ],
            [
                'op'    => 'update',
                'table' => CompatFixture::ITEMS,
                'id'    => 3,
                'patch' => ['qty' => 30],
            ],
            ['op' => 'delete', 'table' => CompatFixture::OWNERS, 'id' => 1],
            ['op' => 'rows', 'table' => CompatFixture::ITEMS],
            [
                'op'    => 'where',
                'table' => CompatFixture::ITEMS,
                'field' => 'ownerId',
                'value' => 2,
            ],
            ['op' => 'validate'],
        ]);

        Assert::same($results[0], 4);
        Assert::same($results[1], 7);
        Assert::same($results[4], $this->rows($db, CompatFixture::ITEMS));
        Assert::same(
            array_column($this->rows($db, CompatFixture::ITEMS), 'id'),
            [3, 4, 5, 6, 7],
        );
        Assert::same(
            $results[5],
            $db->table(CompatFixture::ITEMS)
                ->where('ownerId', '=', 2)
                ->orderBy('id')
                ->selectAllByArray(),
        );
        Assert::same($results[6], []);
        Assert::same(self::issueLines($db->validate()), [
            'info|table_unverified|' . CompatFixture::OWNERS . '|stale',
            'info|table_unverified|' . CompatFixture::ITEMS . '|stale',
        ]);

        $db->repair();

        Assert::same($db->validate()->issues, []);
        Assert::same(
            EngineProcess::legacy($this->dbDir, [['op' => 'validate']]),
            [[]],
        );
    }

    #[Test]
    public function currentEngineServesLegacyDatabase(): void
    {
        EngineProcess::legacy($this->dbDir, [['op' => 'create']]);
        $logger = new SpyLogger();
        $db = JsonDataProvider::getInstance($this->dbDir, null, $logger);

        Assert::count($logger->records, 1);
        Assert::string($logger->records[0]['message'])
            ->contains('storage format generation 1');

        $ownerId = $db->table(CompatFixture::OWNERS)->insertByArray([
            'email' => 'dan@example.test',
            'name'  => 'dan',
        ]);
        $db->table(CompatFixture::ITEMS)->insertByArray([
            'ownerId' => $ownerId,
            'title'   => 'dan-item-1',
            'qty'     => 7,
        ]);
        $db->table(CompatFixture::ITEMS)->updateByIdByArray(3, ['qty' => 30]);
        $db->table(CompatFixture::OWNERS)->deleteById(1);

        [$rows, $byOwner, $issues] = EngineProcess::legacy($this->dbDir, [
            ['op' => 'rows', 'table' => CompatFixture::ITEMS],
            [
                'op'    => 'where',
                'table' => CompatFixture::ITEMS,
                'field' => 'ownerId',
                'value' => 4,
            ],
            ['op' => 'validate'],
        ]);

        Assert::same($rows, $this->rows($db, CompatFixture::ITEMS));
        Assert::same(
            array_column($this->rows($db, CompatFixture::ITEMS), 'id'),
            [3, 4, 5, 6, 7],
        );
        Assert::same(array_column((array)$byOwner, 'title'), ['dan-item-1']);
        Assert::same($issues, []);
        Assert::same($db->validate()->issues, []);
    }

    /**
     * v1.0.0 maintenance (repair, index rebuild) run over a database the
     * current engine wrote finds nothing to fix and changes no data.
     */
    #[Test]
    public function legacyMaintenanceKeepsCurrentDatabaseIntact(): void
    {
        $db = $this->currentDatabase();
        $owners = $this->rows($db, CompatFixture::OWNERS);
        $items = $this->rows($db, CompatFixture::ITEMS);

        [$validated, $repaired] = EngineProcess::legacy($this->dbDir, [
            ['op' => 'validate'],
            ['op' => 'repair'],
            ['op' => 'rebuild', 'table' => CompatFixture::ITEMS],
        ]);

        Assert::same($validated, []);
        Assert::same($repaired, [
            'info|table_optimized|' . CompatFixture::OWNERS,
            'info|table_optimized|' . CompatFixture::ITEMS,
        ]);
        Assert::same($this->rows($db, CompatFixture::OWNERS), $owners);
        Assert::same($this->rows($db, CompatFixture::ITEMS), $items);
        Assert::same(self::issueLines($db->validate()), [
            'info|table_unverified|' . CompatFixture::OWNERS . '|stale',
            'info|table_unverified|' . CompatFixture::ITEMS . '|stale',
        ]);
    }

    #[Test]
    public function backupsCrossBetweenVersions(): void
    {
        $db = $this->currentDatabase();
        $items = $this->rows($db, CompatFixture::ITEMS);
        $current = $db->backup($this->root . '/current.tar.gz');

        [, $legacy, , $restoredByLegacy] = EngineProcess::legacy(
            $this->dbDir,
            [
                ['op' => 'delete', 'table' => CompatFixture::ITEMS, 'id' => 1],
                ['op' => 'backup', 'dest' => $this->root . '/legacy.tar.gz'],
                ['op' => 'restore', 'archive' => $current],
                ['op' => 'rows', 'table' => CompatFixture::ITEMS],
            ],
        );

        Assert::same($restoredByLegacy, $items);
        Assert::true(\is_string($legacy));

        $db->restore($legacy);

        Assert::same(
            array_column($this->rows($db, CompatFixture::ITEMS), 'id'),
            [2, 3, 4, 5, 6],
        );
        Assert::same($db->validate()->issues, []);
        Assert::same(
            EngineProcess::legacy($this->dbDir, [['op' => 'validate']]),
            [[]],
        );
    }

    #[Test]
    public function legacySchemaChangesOnCurrentDatabase(): void
    {
        $db = $this->currentDatabase();
        $renamed = CompatFixture::EXTRAS . '_renamed';

        EngineProcess::legacy($this->dbDir, [
            ['op' => 'createExtra'],
            [
                'op'    => 'addIndex',
                'table' => CompatFixture::EXTRAS,
                'name'  => 'idx_extras_label',
                'field' => 'label',
            ],
            [
                'op'     => 'insert',
                'table'  => CompatFixture::EXTRAS,
                'record' => ['label' => 'x'],
            ],
            [
                'op'   => 'renameTable',
                'from' => CompatFixture::EXTRAS,
                'to'   => $renamed,
            ],
        ]);

        Assert::same(
            $db->table($renamed)->where('label', '=', 'x')->selectAllByArray(),
            [['id' => 1, 'label' => 'x']],
        );
        Assert::same(self::issueLines($db->validate()), [
            'info|table_unverified|' . $renamed . '|unstamped',
        ]);

        [, $issues] = EngineProcess::legacy($this->dbDir, [
            ['op' => 'dropTable', 'table' => $renamed],
            ['op' => 'validate'],
        ]);

        Assert::same($issues, []);
        Assert::same($db->tableNames(), [
            CompatFixture::OWNERS,
            CompatFixture::ITEMS,
        ]);
        Assert::same($db->validate()->issues, []);
    }

    /**
     * Both engines take the same lock files in the same order, so their
     * writers exclude each other: interleaved inserts lose nothing and
     * never mint a duplicate id.
     */
    #[Test]
    public function enginesWritingAtOnceExcludeEachOther(): void
    {
        $this->currentDatabase();
        $count = 150;

        $legacy = EngineProcess::start(true, $this->dbDir, [
            ['op' => 'insertMany', 'count' => $count, 'tag' => 'legacy'],
        ]);
        $current = EngineProcess::start(false, $this->dbDir, [
            ['op' => 'insertMany', 'count' => $count, 'tag' => 'current'],
        ]);

        Assert::same($legacy->wait(), [$count]);
        Assert::same($current->wait(), [$count]);

        $db = JsonDataProvider::getInstance($this->dbDir);
        $rows = $this->rows($db, CompatFixture::ITEMS);
        $ids = array_column($rows, 'id');

        Assert::count($rows, 6 + 2 * $count);
        Assert::same($ids, range(1, 6 + 2 * $count));
        Assert::count(
            array_filter(
                array_column($rows, 'title'),
                static fn (mixed $t): bool => \is_string($t)
                    && str_starts_with($t, 'legacy-'),
            ),
            $count,
        );
        Assert::same($db->validate()->issues, []);
        Assert::same(
            EngineProcess::legacy($this->dbDir, [['op' => 'validate']]),
            [[]],
        );
    }

    /**
     * Archives record the storage generation of the database in a field
     * of their own manifest, keeping the manifest version at 1: v1.0.0
     * restores them as any other archive, and its own archives (without
     * the field) restore here.
     */
    #[Test]
    public function archivesRecordStorageGenerationReadablyForLegacy(): void
    {
        $db = $this->currentDatabase();
        $current = $db->backup($this->root . '/current.tar.gz');
        $legacy = $this->root . '/legacy.tar.gz';

        $manifest = self::archiveManifest($current);
        Assert::same($manifest['version'] ?? null, 1);
        Assert::same(
            $manifest['storageFormat'] ?? null,
            StorageManifest::GENERATION,
        );

        [, , $issues] = EngineProcess::legacy($this->dbDir, [
            ['op' => 'restore', 'archive' => $current],
            ['op' => 'backup', 'dest' => $legacy],
            ['op' => 'validate'],
        ]);

        Assert::same($issues, []);
        Assert::false(
            \array_key_exists('storageFormat', self::archiveManifest($legacy)),
        );

        $db->restore($legacy);

        Assert::true($db->storageStatus()->isCurrent());
    }

    /**
     * The format manifest lives where v1.0.0 does not look: its validation
     * reports nothing, its repair, maintenance, schema changes, backup and
     * restore keep the manifest byte for byte, and no archive of either
     * engine carries it. The current engine then opens the database with
     * no notice at all.
     */
    #[Test]
    public function formatManifestIsInvisibleToLegacyEngine(): void
    {
        $db = $this->currentDatabase();
        $manifest = StorageManifest::path($this->dbDir);
        $bytes = file_get_contents($manifest);
        Assert::true(\is_string($bytes));

        $current = $db->backup($this->root . '/current.tar.gz');
        $legacy = $this->root . '/legacy.tar.gz';

        $results = EngineProcess::legacy($this->dbDir, [
            ['op' => 'validate'],
            ['op' => 'repair'],
            ['op' => 'rebuild', 'table' => CompatFixture::ITEMS],
            ['op' => 'createExtra'],
            ['op' => 'dropTable', 'table' => CompatFixture::EXTRAS],
            ['op' => 'backup', 'dest' => $legacy],
            ['op' => 'restore', 'archive' => $legacy],
            ['op' => 'restore', 'archive' => $current],
            ['op' => 'validate'],
        ]);

        Assert::same($results[0], []);
        Assert::same($results[1], [
            'info|table_optimized|' . CompatFixture::OWNERS,
            'info|table_optimized|' . CompatFixture::ITEMS,
        ]);
        Assert::same($results[8], []);
        Assert::same(file_get_contents($manifest), $bytes);
        Assert::count(self::membersUnder($current, 'tables'), 2);
        Assert::same(self::membersUnder($current, StorageManifest::DIR), []);
        Assert::same(self::membersUnder($legacy, StorageManifest::DIR), []);
        Assert::same(
            EngineProcess::current($this->dbDir, [['op' => 'initNotices']]),
            [[]],
        );
    }

    private function currentDatabase(): JsonDataProvider
    {
        $db = JsonDataProvider::createDatabase($this->dbDir);
        CompatFixture::build($db);
        CompatFixture::seed($db);

        return $db;
    }

    /**
     * Issues of a report as "severity|category|table", with the freshness
     * verdict appended for table_unverified.
     *
     * @return list<string>
     */
    private static function issueLines(IntegrityReport $report): array
    {
        $lines = [];

        foreach ($report->issues as $issue) {
            $line = $issue->severity->value . '|' . $issue->category->value
                . '|' . ($issue->tableName ?? '');
            $freshness = $issue->context['freshness'] ?? null;

            $lines[] = \is_string($freshness)
                ? $line . '|' . $freshness
                : $line;
        }

        return $lines;
    }

    /**
     * The decoded manifest.json of a backup archive.
     *
     * @return array<mixed>
     */
    private static function archiveManifest(string $archive): array
    {
        $decoded = json_decode(
            (string)file_get_contents(
                'phar://' . $archive . '/manifest.json',
            ),
            true,
        );
        Assert::true(\is_array($decoded));

        return $decoded;
    }

    /**
     * Archive members whose path has a segment named $segment.
     *
     * @return list<string>
     */
    private static function membersUnder(
        string $archive,
        string $segment,
    ): array {
        $found = [];
        $members = new \RecursiveIteratorIterator(new \PharData($archive));

        foreach ($members as $member) {
            \assert($member instanceof \PharFileInfo);
            $path = $member->getPathname();

            if (str_contains($path, '/' . $segment . '/')) {
                $found[] = $path;
            }
        }

        return $found;
    }

    /**
     * @return list<array<string,null|scalar>>
     */
    private function rows(JsonDataProvider $db, string $table): array
    {
        return array_values(
            $db->table($table)->orderBy('id')->selectAllByArray(),
        );
    }
}
