<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderDataException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\BrokenRecordPolicyEnum;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * A data-file line that is not a record under a write that rewrites the
 * table. Reads skip such a line, so a rewrite built on the read loses it:
 * under BrokenRecordPolicyEnum::Drop (the default) silently, under Refuse
 * the write fails before the disk is touched. Appends never lose a line,
 * and a torn tail of a crashed append is dropped under either policy.
 */
final class BrokenRecordPolicyTest
{
    private const string OWNERS = 'owners';
    private const string ITEMS = 'items';
    private const int BROKEN_LINE = 1;

    private string $root;

    private string $dbDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-broken-record-policy');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * The default stays what it always was: the rewrite drops the line.
     */
    #[Test]
    public function dropPolicyLosesTheLineOnRewrite(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Drop);
        $this->corruptLine(self::ITEMS, self::BROKEN_LINE);

        $db->table(self::ITEMS)->where('id', '=', 3)
            ->updateByArray(['title' => 'b1x']);

        Assert::false(str_contains($this->bytes(self::ITEMS), '#'));
        Assert::same(
            array_column($db->readAll(self::ITEMS), 'title'),
            ['a1', 'b1x', 'c1'],
        );
    }

    #[Test]
    public function refusedUpdateLeavesTheDiskUntouched(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        $this->corruptLine(self::ITEMS, self::BROKEN_LINE);
        $before = $this->snapshot();

        $this->assertRefused(
            static fn () => $db->table(self::ITEMS)->where('id', '=', 3)
                ->updateByArray(['title' => 'b1x']),
        );

        Assert::same($this->snapshot(), $before);
    }

    #[Test]
    public function refusedDeleteLeavesTheDiskUntouched(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        $this->corruptLine(self::ITEMS, self::BROKEN_LINE);
        $before = $this->snapshot();

        $this->assertRefused(
            static fn () => $db->table(self::ITEMS)->deleteById(3),
        );

        Assert::same($this->snapshot(), $before);
    }

    /**
     * The refusal names the first lost line and counts all of them.
     */
    #[Test]
    public function refusalNamesFirstLineAndCountsAll(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        $this->corruptLine(self::ITEMS, 3);
        $this->corruptLine(self::ITEMS, 2);

        $this->assertRefused(
            static fn () => $db->table(self::ITEMS)->deleteById(1),
            2,
            2,
        );
    }

    /**
     * A cascade that would rewrite the broken child is refused while
     * planning — the parent is not deleted either.
     */
    #[Test]
    public function cascadeIntoBrokenChildIsRefusedBeforeAnyWrite(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        $this->corruptLine(self::ITEMS, self::BROKEN_LINE);
        $before = $this->snapshot();

        $this->assertRefused(
            static fn () => $db->table(self::OWNERS)->deleteById(1),
        );

        Assert::same($this->snapshot(), $before);
    }

    /**
     * Only a table the write actually rewrites is guarded: deleting a
     * parent without children leaves the broken child table as it is.
     */
    #[Test]
    public function cascadeLeavingBrokenChildAloneProceeds(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        $this->corruptLine(self::ITEMS, self::BROKEN_LINE);
        $items = $this->bytes(self::ITEMS);

        Assert::true($db->table(self::OWNERS)->deleteById(4));

        Assert::same($this->bytes(self::ITEMS), $items);
        Assert::same($db->count(self::OWNERS), 3);
    }

    #[Test]
    public function rewriteMatchingNothingIsNotRefused(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        $this->corruptLine(self::ITEMS, self::BROKEN_LINE);
        $before = $this->snapshot();

        Assert::true(
            $db->table(self::ITEMS)->where('id', '=', 999)
                ->updateByArray(['title' => 'x']),
        );
        Assert::true($db->table(self::ITEMS)->deleteById(999));

        Assert::same($this->snapshot(), $before);
    }

    /**
     * An append writes after the last line and loses nothing.
     */
    #[Test]
    public function appendToBrokenTableProceeds(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        $this->corruptLine(self::ITEMS, self::BROKEN_LINE);

        $db->table(self::ITEMS)->insertByArray([
            'title'   => 'd1',
            'ownerId' => 4,
        ]);

        Assert::true(str_contains($this->bytes(self::ITEMS), '#'));
        Assert::same(
            array_column($db->readAll(self::ITEMS), 'title'),
            ['a1', 'b1', 'c1', 'd1'],
        );
    }

    /**
     * When the gate must heal the table, the heal is a rewrite too: it is
     * refused, every write fails, reads keep working.
     */
    #[Test]
    public function selfHealIsRefusedAndReadsKeepWorking(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        $this->insertForeignLine(self::ITEMS, 2, '{not a record');
        $before = $this->snapshot();

        $this->assertRefused(
            static fn () => $db->table(self::ITEMS)->insertByArray([
                'title'   => 'd1',
                'ownerId' => 4,
            ]),
            2,
            1,
        );

        Assert::same($this->snapshot(), $before);
        Assert::same(
            array_column($db->readAll(self::ITEMS), 'title'),
            ['a1', 'a2', 'b1', 'c1'],
        );
    }

    /**
     * A torn tail of a crashed append was never acknowledged: the heal
     * drops it, under Refuse as well.
     */
    #[Test]
    public function tornTailIsDroppedUnderRefuse(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        file_put_contents(
            $this->dataFile(self::ITEMS),
            '{"id":99,"title":"to',
            FILE_APPEND,
        );

        $db->table(self::ITEMS)->insertByArray([
            'title'   => 'd1',
            'ownerId' => 4,
        ]);

        Assert::false(str_contains($this->bytes(self::ITEMS), '"to'));
        Assert::same(
            array_column($db->readAll(self::ITEMS), 'title'),
            ['a1', 'a2', 'b1', 'c1', 'd1'],
        );
        Assert::false($db->validate()->hasErrors());
    }

    /**
     * Column DDL rewrites every row; each refuses before the schema is
     * touched.
     */
    #[Test]
    public function columnDdlIsRefusedBeforeTheSchemaChanges(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        $this->corruptLine(self::ITEMS, self::BROKEN_LINE);
        $before = $this->snapshot();

        $this->assertRefused(
            static fn () => $db->renameColumn(self::ITEMS, 'title', 'name'),
        );
        $this->assertRefused(
            static fn () => $db->reorderColumns(
                self::ITEMS,
                ['ownerId', 'title'],
            ),
        );
        $this->assertRefused(
            static fn () => $db->migrateColumns(TableSchema::create(
                name: self::ITEMS,
                columns: [
                    'title'   => ColumnTypes::STRING,
                    'ownerId' => ColumnTypes::INT,
                    'note'    => ColumnTypes::STRING_NULLABLE,
                ],
            )),
        );

        Assert::same($this->snapshot(), $before);
    }

    /**
     * Replacing the whole content on purpose loses nothing the caller
     * means to keep.
     */
    #[Test]
    public function wholesaleReplacementIsNotRefused(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        $this->corruptLine(self::ITEMS, self::BROKEN_LINE);

        $db->importRecords(self::ITEMS, [
            ['id' => 10, 'title' => 'x1', 'ownerId' => 1],
        ]);
        Assert::same(array_column($db->readAll(self::ITEMS), 'title'), ['x1']);

        $this->corruptLine(self::ITEMS, 0);
        $db->truncate(self::ITEMS);
        Assert::same($this->bytes(self::ITEMS), '');
    }

    /**
     * The policy is per instance: switching back to Drop lets the same
     * rewrite through.
     */
    #[Test]
    public function policyIsSwitchable(): void
    {
        $db = $this->database(BrokenRecordPolicyEnum::Refuse);
        $this->corruptLine(self::ITEMS, self::BROKEN_LINE);

        $this->assertRefused(
            static fn () => $db->table(self::ITEMS)->deleteById(3),
        );

        $db->setBrokenRecordPolicy(BrokenRecordPolicyEnum::Drop);
        Assert::true($db->table(self::ITEMS)->deleteById(3));
        Assert::same(
            array_column($db->readAll(self::ITEMS), 'title'),
            ['a1', 'c1'],
        );
    }

    private function database(BrokenRecordPolicyEnum $policy): JsonDataProvider
    {
        $db = JsonDataProvider::createDatabase($this->dbDir);
        $db->createTable(TableSchema::create(
            name: self::OWNERS,
            columns: ['name' => ColumnTypes::STRING],
        ));
        $db->createTable(TableSchema::create(
            name: self::ITEMS,
            columns: [
                'title'   => ColumnTypes::STRING,
                'ownerId' => ColumnTypes::INT,
            ],
        ));
        $db->addRelation(new RelationSchema(
            fromTable: self::ITEMS,
            foreignKey: 'ownerId',
            toTable: self::OWNERS,
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $db->table(self::OWNERS)->insertByArray(['name' => $name]);
        }

        foreach ([['a1', 1], ['a2', 1], ['b1', 2], ['c1', 3]] as [$t, $o]) {
            $db->table(self::ITEMS)->insertByArray([
                'title'   => $t,
                'ownerId' => $o,
            ]);
        }

        return $db->setBrokenRecordPolicy($policy);
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function assertRefused(
        \Closure $write,
        int $line = self::BROKEN_LINE,
        int $count = 1,
    ): void {
        try {
            $write();
            Assert::fail('a rewrite went through a line that is not a record');
        } catch (JsonProviderDataException $e) {
            Assert::same(
                $e->error,
                JsonProviderErrorEn::BrokenRecordBlocksRewrite
            );
            Assert::string($e->getMessage())->contains('"' . self::ITEMS . '"');
            Assert::string($e->getMessage())->contains(' ' . $line . ',');
            Assert::true(
                preg_match(
                    '/\(' . $count . ' in all\)|всего: ' . $count . '\)/u',
                    $e->getMessage(),
                ) === 1,
            );
        }
    }

    /**
     * Overwrites one line with bytes of the same length that are not JSON,
     * in place: size and inode stay, so the table stays trusted.
     */
    private function corruptLine(string $table, int $line): void
    {
        $path = $this->dataFile($table);
        $lines = explode("\n", $this->bytes($table));
        $lines[$line] = str_repeat('#', \strlen($lines[$line]));
        file_put_contents($path, implode("\n", $lines));
    }

    /**
     * Inserts a foreign line before the given line: the size no longer
     * matches meta, so the next write heals the table.
     */
    private function insertForeignLine(
        string $table,
        int $before,
        string $text,
    ): void {
        $lines = explode("\n", $this->bytes($table));
        array_splice($lines, $before, 0, [$text]);
        file_put_contents($this->dataFile($table), implode("\n", $lines));
    }

    /**
     * Every file of the database, by relative path.
     *
     * @return array<string,string>
     */
    private function snapshot(): array
    {
        $files = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->dbDir,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            if (
                $entry->isFile()
                && !str_contains($entry->getPathname(), '/.locks/')
            ) {
                $files[substr($entry->getPathname(), \strlen($this->dbDir))]
                    = (string)file_get_contents($entry->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    private function bytes(string $table): string
    {
        clearstatcache();

        return (string)file_get_contents($this->dataFile($table));
    }

    private function dataFile(string $table): string
    {
        return $this->dbDir . '/' . $table . '/'
            . TableSchema::dataFileName($table);
    }
}
