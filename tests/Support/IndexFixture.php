<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\JsonTable;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use Psr\Log\NullLogger;

/**
 * Fixture of IndexBench: pairs of tables holding the SAME rows and differing
 * in exactly one property — a unique constraint, the kind of index behind a
 * foreign key, the shape of an index, the table size, the freshness of the
 * stamp — so that each benchmark's pair isolates that property.
 *
 * The pairs that pit an index path against a full scan exist twice: over
 * narrow rows (a few integers) and over wide ones (about 200 bytes). A full
 * scan pays per byte of data, an index lookup pays per index entry, so the
 * winner depends on the row width — measuring one width alone would turn
 * that dependence into a wrong general conclusion.
 *
 * The fixture uses only API the 1.0 engine has, so the same benchmarks run
 * against both engines in a before/after comparison. On the 1.0 engine the
 * stale table is simply fresh: that engine has no stamp to go stale.
 *
 * seed() checks every pair before any benchmark runs: both sides must return
 * the same rows, and the stale table must actually be stale. A pair that does
 * not compare like with like would produce a number, but a wrong one.
 *
 * The provider gets a NullLogger: the stale table is reported once per
 * instance, and without a logger that report would become a PHP user
 * deprecation inside a timed loop.
 */
final class IndexFixture
{
    public const string DB_PREFIX = 'jp-bench-index';
    public const int ROWS = 100000;
    public const int SMALL_ROWS = 1000;
    public const int OWNERS = 1000;
    public const int PAIR_A = 1000;
    public const int PAIR_B = 100;

    public const string OWNER = 'ix_owners';
    public const string UNIQUE = 'ix_unique';
    public const string PLAIN = 'ix_plain';
    public const string FK_SERVICE = 'ix_fk_service';
    public const string FK_USER = 'ix_fk_user';
    public const string COMPOSITE = 'ix_composite';
    public const string SINGLE = 'ix_single';
    public const string SMALL = 'ix_small';
    public const string STALE = 'ix_stale';
    public const string FRESH = 'ix_fresh';
    public const string FK_SERVICE_WIDE = 'ix_fk_service_wide';
    public const string FK_USER_WIDE = 'ix_fk_user_wide';
    public const string STALE_WIDE = 'ix_stale_wide';
    public const string FRESH_WIDE = 'ix_fresh_wide';

    /**
     * Length of the text payload of a wide row; with the other fields a
     * wide row is about 200 bytes, as a record with a text and timestamps.
     */
    public const int WIDE_TEXT = 160;

    private static bool $seeded = false;

    private static int $prevTimeLimit = 0;

    public static function dbPath(): string
    {
        return TempDir::root(self::DB_PREFIX);
    }

    public static function db(): JsonDataProvider
    {
        if (!self::$seeded) {
            self::seed();
        }

        return JsonDataProvider::getInstance(
            self::dbPath(),
            null,
            new NullLogger(),
        );
    }

    /**
     * Creates the database, fills every table, makes the stale table stale
     * and verifies every pair.
     */
    public static function seed(): void
    {
        TempDir::remove(self::dbPath());
        self::$seeded = true;
        self::$prevTimeLimit = (int)\ini_get('max_execution_time');
        set_time_limit(0);

        $db = JsonDataProvider::createDatabase(
            self::dbPath(),
            null,
            new NullLogger(),
        );

        self::createTables($db);
        self::fill($db);
        self::makeStale();
        self::verify($db);
    }

    /**
     * Removes the database and restores the execution-time limit seed()
     * lifted.
     */
    public static function drop(): void
    {
        TempDir::remove(self::dbPath());
        self::$seeded = false;
        set_time_limit(self::$prevTimeLimit);
    }

    /**
     * The owner whose rows the foreign-key benchmarks select: ROWS / OWNERS
     * rows each.
     */
    public static function owner(): int
    {
        return 500;
    }

    /**
     * The filter of one count() variation over the wide fresh table. Both
     * sides of a count benchmark start from it — one counts, the other
     * selects — so they always ask the same question.
     */
    public static function countQuery(string $variant): JsonTable
    {
        $table = self::db()->table(self::FRESH_WIDE);

        return match ($variant) {
            'in'       => $table->where('val', 'IN', [1, 50, 100, 500, 999]),
            'range'    => $table->where('val', 'BETWEEN', [100, 104]),
            'like'     => $table->where('title', 'LIKE', 'title of row 7%'),
            'compound' => $table->where('val', '=', 7)
                ->where('title', 'LIKE', 'title of row 1%'),
            'distinct' => $table->distinct('val'),
            default    => throw new \InvalidArgumentException($variant),
        };
    }

    public static function dataFile(string $table): string
    {
        return self::dbPath() . '/' . $table . '/'
            . TableSchema::dataFileName($table);
    }

    private static function createTables(JsonDataProvider $db): void
    {
        $db->createTable(TableSchema::create(
            name: self::OWNER,
            columns: ['name' => ColumnTypes::STRING],
        ));

        foreach ([self::UNIQUE, self::PLAIN] as $table) {
            $db->createTable(TableSchema::create(
                name: $table,
                uniqueConstraints: $table === self::UNIQUE
                    ? [new UniqueConstraint('uq_email', ['email'])]
                    : [],
                columns: [
                    'email' => ColumnTypes::STRING,
                    'val'   => ColumnTypes::INT,
                ],
                indexes: [self::index('idx_val', 'val')],
            ));
        }

        foreach ([self::FK_SERVICE, self::FK_USER] as $table) {
            $db->createTable(TableSchema::create(
                name: $table,
                columns: [
                    'ownerId' => ColumnTypes::INT,
                    'val'     => ColumnTypes::INT,
                ],
                indexes: $table === self::FK_USER
                    ? [self::index('idx_owner', 'ownerId')]
                    : [],
            ));
            $db->addRelation(new RelationSchema(
                fromTable: $table,
                foreignKey: 'ownerId',
                toTable: self::OWNER,
                references: 'id',
                type: RelationTypeEnum::BELONGS_TO,
                onDelete: ForeignKeyActionEnum::CASCADE,
            ));
        }

        $db->createTable(TableSchema::create(
            name: self::COMPOSITE,
            columns: ['a' => ColumnTypes::INT, 'b' => ColumnTypes::INT],
            indexes: [new IndexSchema('idx_ab', [
                new IndexFieldSchema('a', SortDirectionEnum::ASC),
                new IndexFieldSchema('b', SortDirectionEnum::ASC),
            ])],
        ));
        $db->createTable(TableSchema::create(
            name: self::SINGLE,
            columns: ['a' => ColumnTypes::INT, 'b' => ColumnTypes::INT],
            indexes: [self::index('idx_a', 'a')],
        ));

        foreach ([self::SMALL, self::STALE, self::FRESH] as $table) {
            $db->createTable(TableSchema::create(
                name: $table,
                columns: ['val' => ColumnTypes::INT],
                indexes: [self::index('idx_val', 'val')],
            ));
        }

        foreach ([self::FK_SERVICE_WIDE, self::FK_USER_WIDE] as $table) {
            $db->createTable(TableSchema::create(
                name: $table,
                columns: [
                    'ownerId' => ColumnTypes::INT,
                    'val'     => ColumnTypes::INT,
                ] + self::wideColumns(),
                indexes: $table === self::FK_USER_WIDE
                    ? [self::index('idx_owner', 'ownerId')]
                    : [],
            ));
            $db->addRelation(new RelationSchema(
                fromTable: $table,
                foreignKey: 'ownerId',
                toTable: self::OWNER,
                references: 'id',
                type: RelationTypeEnum::BELONGS_TO,
                onDelete: ForeignKeyActionEnum::CASCADE,
            ));
        }

        foreach ([self::STALE_WIDE, self::FRESH_WIDE] as $table) {
            $db->createTable(TableSchema::create(
                name: $table,
                columns: ['val' => ColumnTypes::INT] + self::wideColumns(),
                indexes: [self::index('idx_val', 'val')],
            ));
        }
    }

    /**
     * @return array<string,string>
     */
    private static function wideColumns(): array
    {
        return [
            'title'     => ColumnTypes::STRING,
            'text'      => ColumnTypes::STRING,
            'createdAt' => ColumnTypes::STRING,
        ];
    }

    /**
     * The payload of a wide row: distinct per row, fixed in length.
     *
     * @return array<string,string>
     */
    private static function widePayload(int $i): array
    {
        $text = str_pad('row ' . $i . ' ', self::WIDE_TEXT, 'lorem ipsum ');

        return [
            'title'     => 'title of row ' . $i,
            'text'      => $text,
            'createdAt' => \sprintf(
                '2026-%02d-%02dT%02d:%02d:00+00:00',
                $i % 12 + 1,
                $i % 28 + 1,
                $i % 24,
                $i % 60,
            ),
        ];
    }

    private static function fill(JsonDataProvider $db): void
    {
        $owners = [];

        for ($i = 1; $i <= self::OWNERS; $i++) {
            $owners[] = ['id' => $i, 'name' => 'owner' . $i];
        }

        $db->importRecords(self::OWNER, $owners);

        $emails = [];
        $fks = [];
        $pairs = [];
        $vals = [];
        $wideFks = [];
        $wideVals = [];

        for ($i = 1; $i <= self::ROWS; $i++) {
            $emails[] = [
                'id'    => $i,
                'email' => 'user' . $i . '@example.test',
                'val'   => $i % 1000,
            ];
            $fks[] = [
                'id'      => $i,
                'ownerId' => ($i % self::OWNERS) + 1,
                'val'     => $i % 1000,
            ];
            $pairs[] = [
                'id' => $i,
                'a'  => $i % self::PAIR_A,
                'b'  => intdiv($i, self::PAIR_A) % self::PAIR_B,
            ];
            $vals[] = ['id' => $i, 'val' => $i % 1000];
            $wide = self::widePayload($i);
            $wideFks[] = [
                'id'      => $i,
                'ownerId' => ($i % self::OWNERS) + 1,
                'val'     => $i % 1000,
            ] + $wide;
            $wideVals[] = ['id' => $i, 'val' => $i % 1000] + $wide;
        }

        foreach ([self::UNIQUE, self::PLAIN] as $table) {
            $db->importRecords($table, $emails);
        }

        foreach ([self::FK_SERVICE, self::FK_USER] as $table) {
            $db->importRecords($table, $fks);
        }

        foreach ([self::COMPOSITE, self::SINGLE] as $table) {
            $db->importRecords($table, $pairs);
        }

        foreach ([self::STALE, self::FRESH] as $table) {
            $db->importRecords($table, $vals);
        }

        foreach ([self::FK_SERVICE_WIDE, self::FK_USER_WIDE] as $table) {
            $db->importRecords($table, $wideFks);
        }

        foreach ([self::STALE_WIDE, self::FRESH_WIDE] as $table) {
            $db->importRecords($table, $wideVals);
        }

        $db->importRecords(
            self::SMALL,
            \array_slice($vals, 0, self::SMALL_ROWS),
        );
    }

    /**
     * Replaces the stale table's data file the way a full rewrite does — a
     * temp sibling renamed over it, same bytes — without a stamped commit:
     * the state a 1.0 engine leaves after rewriting a generation-2 table.
     */
    private static function makeStale(): void
    {
        foreach ([self::STALE, self::STALE_WIDE] as $table) {
            $path = self::dataFile($table);
            $bytes = file_get_contents($path);

            if ($bytes === false) {
                throw new \RuntimeException('cannot read ' . $path);
            }

            file_put_contents($path . '.stale.tmp', $bytes);
            rename($path . '.stale.tmp', $path);
        }
    }

    private static function verify(JsonDataProvider $db): void
    {
        $owner = self::owner();
        self::same(
            'foreign-key pair',
            $db->table(self::FK_SERVICE)->where('ownerId', '=', $owner)
                ->orderBy('id')->selectAllByArray(),
            $db->table(self::FK_USER)->where('ownerId', '=', $owner)
                ->orderBy('id')->selectAllByArray(),
            self::ROWS / self::OWNERS,
        );
        self::same(
            'composite pair',
            $db->table(self::COMPOSITE)->where('a', '=', 7)
                ->where('b', '=', 42)->selectAllByArray(),
            $db->table(self::SINGLE)->where('a', '=', 7)
                ->where('b', '=', 42)->selectAllByArray(),
            1,
        );
        self::same(
            'size pair',
            $db->table(self::PLAIN)->where('id', '=', 700)
                ->selectAllByArray(),
            [['id' => 700, 'email' => 'user700@example.test', 'val' => 700]],
            1,
        );
        self::same(
            'size pair (small side)',
            $db->table(self::SMALL)->where('id', '=', 700)
                ->selectAllByArray(),
            [['id' => 700, 'val' => 700]],
            1,
        );
        self::same(
            'wide foreign-key pair',
            $db->table(self::FK_SERVICE_WIDE)->where('ownerId', '=', $owner)
                ->orderBy('id')->selectAllByArray(),
            $db->table(self::FK_USER_WIDE)->where('ownerId', '=', $owner)
                ->orderBy('id')->selectAllByArray(),
            self::ROWS / self::OWNERS,
        );

        foreach (['in', 'range', 'like', 'compound'] as $variant) {
            $counted = self::countQuery($variant)->count();
            $selected = \count(self::countQuery($variant)->selectAllByArray());

            if ($counted !== $selected || $counted === 0) {
                throw new \RuntimeException(
                    'count pair ' . $variant . ': ' . $counted . ' vs '
                        . $selected,
                );
            }
        }

        if (self::countQuery('distinct')->count() !== 1000) {
            throw new \RuntimeException('count pair distinct');
        }

        $all = [
            $db->table(self::STALE_WIDE)->count(),
            $db->table(self::FRESH_WIDE)->count(),
        ];

        if ($all !== [self::ROWS, self::ROWS]) {
            throw new \RuntimeException('count pair stale table');
        }

        $freshness = [
            self::STALE      => self::FRESH,
            self::STALE_WIDE => self::FRESH_WIDE,
        ];

        foreach ($freshness as $stale => $fresh) {
            self::same(
                'freshness pair ' . $stale,
                $db->table($stale)->where('val', '=', 7)
                    ->orderBy('id')->selectAllByArray(),
                $db->table($fresh)->where('val', '=', 7)
                    ->orderBy('id')->selectAllByArray(),
                self::ROWS / 1000,
            );

            $counted = $db->table($fresh)->where('val', '=', 7)->count();

            if ($counted !== self::ROWS / 1000) {
                throw new \RuntimeException('count pair: ' . $counted);
            }

            self::assertStale($stale);
        }
    }

    /**
     * On an engine with table stamps, the stamp of $table must not match
     * its data file; on the 1.0 engine there is no stamp to compare.
     */
    private static function assertStale(string $table): void
    {
        $meta = json_decode(
            (string)file_get_contents(self::dbPath() . '/meta.json'),
            true,
        );
        $stamp = \is_array($meta) && \is_array($meta[$table] ?? null)
            ? ($meta[$table]['dataIno'] ?? null)
            : null;

        if ($stamp !== null && $stamp === fileinode(self::dataFile($table))) {
            throw new \RuntimeException($table . ' is not stale');
        }
    }

    /**
     * @param array<mixed> $left
     * @param array<mixed> $right
     */
    private static function same(
        string $pair,
        array $left,
        array $right,
        int $rows,
    ): void {
        if ($left !== $right || \count($left) !== $rows) {
            throw new \RuntimeException(
                $pair . ': the two sides do not return the same '
                    . $rows . ' rows',
            );
        }
    }

    private static function index(string $name, string $field): IndexSchema
    {
        return new IndexSchema(
            $name,
            [new IndexFieldSchema($field, SortDirectionEnum::ASC)],
        );
    }
}
