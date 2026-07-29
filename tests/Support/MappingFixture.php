<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Tests\Support\Dto\MapCategoryDto;

/**
 * Fixture for the mapping benchmarks: one wide table plus the tables needed to
 * measure foreign-key work.
 *
 * Where LoadFixture answers "what does this cost at 5M rows", this one answers
 * "what does the object surface cost, and what does a filter/sort/relation mode
 * cost" — so it is deliberately small (ROWS rows in one table) and rich instead
 * of large and flat. Everything the mapper can charge for is present in
 * `map_rows`: plain scalars, a nullable int, a nullable string, a string column
 * that a DTO may bind either as `string` or as a backed enum, and two datetime
 * columns (one nullable) that cost a TemporalCodec parse per row.
 *
 * Columns whose only job is selectivity:
 *   - `bucket`   = id % 1000 → each value matches ROWS/1000 rows (0.1%)
 *   - `decile`   = id % 10   → each value matches ROWS/10 rows (10%)
 *   - `status`   = one of three values → ~33% each
 *   - `note`     = unindexed, mirrors `bucket`'s selectivity for the
 *                  indexed-vs-unindexed comparison
 *   - `title`    = mixed-case Latin and Cyrillic, so ComparisonMode::Locale
 *                  orders it differently from the byte-ordered index
 *
 * Indexes: `bucket`, `category_id`, `title`. `note` and `decile` are left
 * unindexed on purpose — they are the control side of the filter benchmarks.
 *
 * Relations: `map_rows.category_id → map_categories.id` (belongsTo) and
 * `map_children.row_id → map_rows.id` (belongsTo, ON DELETE CASCADE).
 * `map_plain` mirrors `map_children` without any relation, so the cost of FK
 * enforcement is a difference of two real operations rather than a comparison
 * against a stub.
 *
 * The DB lives under a run-unique directory (see TempDir) and is removed at
 * process shutdown.
 */
final class MappingFixture
{
    public const string DB_PREFIX = 'jp-bench-mapping';

    public const int ROWS = 100000;
    public const int CATEGORIES = 100;
    public const int CHILDREN = 20000;

    /** Number of distinct `bucket` values: each matches ROWS / BUCKETS rows. */
    public const int BUCKETS = 1000;

    /** Parents that own children: CHILDREN / CHILD_PARENTS children each. */
    public const int CHILD_PARENTS = 2000;

    public const string ROWS_TABLE = 'map_rows';
    public const string CATEGORIES_TABLE = 'map_categories';
    public const string CHILDREN_TABLE = 'map_children';
    public const string PLAIN_TABLE = 'map_plain';

    /**
     * Title prefixes: mixed case plus Cyrillic, so byte order and collator
     * order disagree and ComparisonMode::Locale has something to do.
     */
    private const array TITLES = [
        'apple', 'Apple', 'banana', 'Banana', 'cherry', 'Cherry',
        'zebra', 'Zebra', 'ёлка', 'Ёлка', 'яблоко', 'Яблоко',
        'арбуз', 'Арбуз', 'ящик', 'Ящик', 'ostrich', 'Ostrich',
        'úva', 'Úva',
    ];

    private static bool $seeded = false;

    private static string | null $boundDto = null;

    private static bool $categoriesBound = false;

    /**
     * The run-unique database directory: "<temp>/jp-bench-mapping-<token>".
     */
    public static function dbPath(): string
    {
        return TempDir::root(self::DB_PREFIX);
    }

    /**
     * Seeds the DB once per process. Read benchmarks call this through db(),
     * so seeding happens during warmup and never inside a timed loop.
     */
    public static function boot(): void
    {
        if (self::$seeded) {
            return;
        }

        self::$seeded = true;
        self::seedAll();
    }

    public static function db(): JsonDataProvider
    {
        self::boot();

        return JsonDataProvider::getInstance(self::dbPath());
    }

    /**
     * Binds a DTO class to `map_rows`, replacing whatever was bound before.
     * A table holds one binding at a time, so the mapping benchmarks switch
     * between the narrow/scalar/enum/full DTOs through this. Idempotent, and
     * cheap enough after the first call that a benchmark can call it on every
     * iteration — the compile only happens when the binding actually changes.
     *
     * @param class-string $dto
     */
    public static function bind(string $dto): JsonDataProvider
    {
        $db = self::db();

        if (self::$boundDto === $dto) {
            return $db;
        }

        if (self::$boundDto !== null) {
            $db->unregisterDto(self::ROWS_TABLE);
        }

        $db->registerDto($dto);
        self::$boundDto = $dto;

        return $db;
    }

    /**
     * Binds MapCategoryDto to the parent table, once per process. The join
     * benchmark needs both sides of the relation as objects, and the parent
     * binding never has to change.
     */
    public static function bindCategories(): JsonDataProvider
    {
        $db = self::db();

        if (!self::$categoriesBound) {
            $db->registerDto(MapCategoryDto::class);
            self::$categoriesBound = true;
        }

        return $db;
    }

    /**
     * Drops the DB and forgets the process-local state, so a later boot()
     * rebuilds it from scratch.
     */
    public static function drop(): void
    {
        TempDir::remove(self::dbPath());
        self::$seeded = false;
        self::$boundDto = null;
        self::$categoriesBound = false;
    }

    /**
     * The `bucket` value a "0.1% of the table" filter should ask for.
     */
    public static function narrowBucket(): int
    {
        return 7;
    }

    /**
     * The `note` value matching exactly as many rows as narrowBucket() does,
     * on a column with no index behind it.
     */
    public static function narrowNote(): string
    {
        return 'note-7';
    }

    private static function seedAll(): void
    {
        $path = self::dbPath();
        $db = JsonDataProvider::exists($path)
            ? JsonDataProvider::getInstance($path)
            : JsonDataProvider::createDatabase($path);

        $categories = self::categoriesSchema();
        $rows = self::rowsSchema();
        $children = self::childrenSchema();
        $plain = self::plainSchema();

        $db->createTable($categories);
        $db->createTable($rows);
        $db->createTable($children);
        $db->createTable($plain);

        $db->addRelation(new RelationSchema(
            fromTable: self::ROWS_TABLE,
            foreignKey: 'category_id',
            toTable: self::CATEGORIES_TABLE,
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
        ));
        $db->addRelation(new RelationSchema(
            fromTable: self::CHILDREN_TABLE,
            foreignKey: 'row_id',
            toTable: self::ROWS_TABLE,
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        $storage = new NdjsonStorage($path);

        self::write($db, $storage, $categories, self::categoryRows());
        self::write($db, $storage, $rows, self::mapRows());
        self::write($db, $storage, $children, self::childRows());
        self::write($db, $storage, $plain, self::childRows());
    }

    /**
     * Bulk-loads one table and rebuilds its indexes — the same shortcut
     * LoadFixture uses, orders of magnitude faster than per-row insert().
     *
     * @param array<int,array<string,null|scalar>> $rows
     */
    private static function write(
        JsonDataProvider $db,
        NdjsonStorage $storage,
        TableSchema $schema,
        array $rows,
    ): void {
        $storage->write($schema->name, $schema->getFileName(), $rows);
        $db->invalidateCache($schema->name);
        $db->table($schema->name)->rebuildAllIndexes();
    }

    /**
     * @return array<int,array<string,null|scalar>>
     */
    private static function categoryRows(): array
    {
        $rows = [];

        for ($i = 1; $i <= self::CATEGORIES; $i++) {
            $rows[] = [
                'id'   => $i,
                'name' => 'cat-' . $i,
                'sort' => self::CATEGORIES - $i,
            ];
        }

        return $rows;
    }

    /**
     * Datetimes are written straight to storage, bypassing the encoding write
     * path, so they must already be in the canonical UTC form the decoder
     * expects.
     *
     * @return array<int,array<string,null|scalar>>
     */
    private static function mapRows(): array
    {
        $statuses = ['draft', 'active', 'archived'];
        $base = new \DateTimeImmutable(
            '2026-01-01 00:00:00',
            new \DateTimeZone('UTC'),
        );
        $titles = self::TITLES;
        $titleCount = \count($titles);
        $rows = [];

        for ($i = 1; $i <= self::ROWS; $i++) {
            $created = $base->modify('+' . $i . ' seconds');
            $title = $titles[$i % $titleCount];

            $rows[] = [
                'id'          => $i,
                'sku'         => \sprintf('SKU-%06d', $i),
                'category_id' => ($i % self::CATEGORIES) + 1,
                'status'      => $statuses[$i % 3],
                'bucket'      => $i % self::BUCKETS,
                'decile'      => $i % 10,
                'price'       => round($i * 1.5, 2),
                'qty'         => $i % 3 === 0 ? null : $i % 500,
                'created_at'  => $created->format('Y-m-d H:i:s'),
                'updated_at'  => $i % 4 === 0
                    ? null
                    : $created->modify('+1 hour')->format('Y-m-d H:i:s'),
                'title' => $title . '-' . \sprintf('%06d', $i),
                'note'  => $i % 5 === 0
                    ? null
                    : 'note-' . ($i % self::BUCKETS),
                'flag' => $i % 2 === 0,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int,array<string,null|scalar>>
     */
    private static function childRows(): array
    {
        $rows = [];

        for ($i = 1; $i <= self::CHILDREN; $i++) {
            $rows[] = [
                'id'      => $i,
                'row_id'  => ($i % self::CHILD_PARENTS) + 1,
                'payload' => 'payload-' . $i,
            ];
        }

        return $rows;
    }

    private static function categoriesSchema(): TableSchema
    {
        return TableSchema::create(
            name: self::CATEGORIES_TABLE,
            columns: [
                'id'   => ColumnTypes::INT,
                'name' => ColumnTypes::STRING,
                'sort' => ColumnTypes::INT,
            ],
        );
    }

    private static function rowsSchema(): TableSchema
    {
        return TableSchema::create(
            name: self::ROWS_TABLE,
            columns: [
                'id'          => ColumnTypes::INT,
                'sku'         => ColumnTypes::STRING,
                'category_id' => ColumnTypes::INT,
                'status'      => ColumnTypes::STRING,
                'bucket'      => ColumnTypes::INT,
                'decile'      => ColumnTypes::INT,
                'price'       => ColumnTypes::FLOAT,
                'qty'         => ColumnTypes::INT_NULLABLE,
                'created_at'  => ColumnTypes::DATETIME,
                'updated_at'  => ColumnTypes::DATETIME_NULLABLE,
                'title'       => ColumnTypes::STRING,
                'note'        => ColumnTypes::STRING_NULLABLE,
                'flag'        => ColumnTypes::BOOL,
            ],
            indexes: [
                self::index('idx_bucket', 'bucket'),
                self::index('idx_category', 'category_id'),
                self::index('idx_title', 'title'),
            ],
        );
    }

    private static function childrenSchema(): TableSchema
    {
        return TableSchema::create(
            name: self::CHILDREN_TABLE,
            columns: [
                'id'      => ColumnTypes::INT,
                'row_id'  => ColumnTypes::INT,
                'payload' => ColumnTypes::STRING,
            ],
        );
    }

    private static function plainSchema(): TableSchema
    {
        return TableSchema::create(
            name: self::PLAIN_TABLE,
            columns: [
                'id'      => ColumnTypes::INT,
                'row_id'  => ColumnTypes::INT,
                'payload' => ColumnTypes::STRING,
            ],
        );
    }

    private static function index(string $name, string $field): IndexSchema
    {
        return new IndexSchema(
            name: $name,
            fields: [new IndexFieldSchema($field, SortDirectionEnum::ASC)],
        );
    }
}
