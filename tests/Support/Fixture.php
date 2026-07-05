<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;

/**
 * Shared read-mostly fixture for JsonProvider integration tests.
 *
 * The DB is created once per test-run process (guarded by static state)
 * and seeded with data. Tables:
 *   - categories (10 rows): id, name, sort
 *   - products  (100 rows): id, name, category_id, price, in_stock
 *   - tags       (100 rows): id, product_id, label
 *
 * Indexes:
 *   - products: idx_category (category_id asc),
 *               idx_price_desc (price desc)
 *   - tags:     idx_product  (product_id asc)
 */
final class Fixture
{
    public const string DB_PATH = '/tmp/test_json_db';

    private static bool $initialized = false;

    /** @var array<int,array<string,null|scalar>> */
    private static array $productsFixture = [];

    /** @var array<int,array<string,null|scalar>> */
    private static array $categoriesFixture = [];

    /** @var array<int,array<string,null|scalar>> */
    private static array $tagsFixture = [];

    /**
     * Creates (if missing) and seeds the shared test DB.
     * Idempotent: a repeat call is a no-op.
     */
    public static function boot(): void
    {
        if (self::$initialized) {
            return;
        }

        if (JsonDataProvider::exists(self::DB_PATH)) {
            self::drop();
        }

        $db = JsonDataProvider::createDatabase(self::DB_PATH);

        self::createSchema($db);
        self::seedCategories($db);
        self::seedProducts($db);
        self::seedTags($db);

        self::$initialized = true;
    }

    /**
     * Returns the provider singleton (booting the fixture if needed).
     */
    public static function db(): JsonDataProvider
    {
        self::boot();

        return JsonDataProvider::getInstance(self::DB_PATH);
    }

    /**
     * @return array<int,array<string,null|scalar>>
     */
    public static function products(): array
    {
        self::boot();

        return self::$productsFixture;
    }

    /**
     * @return array<int,array<string,null|scalar>>
     */
    public static function categories(): array
    {
        self::boot();

        return self::$categoriesFixture;
    }

    /**
     * @return array<int,array<string,null|scalar>>
     */
    public static function tags(): array
    {
        self::boot();

        return self::$tagsFixture;
    }

    /**
     * Removes the shared DB directory recursively.
     */
    public static function drop(): void
    {
        $path = self::DB_PATH;

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

    private static function createSchema(JsonDataProvider $db): void
    {
        $db->createTable(TableSchema::create(
            name: 'categories',
            uniqueConstraints: [],
            columns: ['id' => 'int', 'name' => 'string', 'sort' => 'int'],
            indexes: [],
        ));

        $db->createTable(TableSchema::create(
            name: 'products',
            uniqueConstraints: [],
            columns: [
                'id'          => 'int',
                'name'        => 'string',
                'category_id' => 'int',
                'price'       => 'float',
                'in_stock'    => 'bool',
            ],
            indexes: [
                new IndexSchema(
                    name: 'idx_category',
                    fields: [new IndexFieldSchema(
                        'category_id',
                        SortDirectionEnum::ASC,
                    )],
                ),
                new IndexSchema(
                    name: 'idx_price_desc',
                    fields: [new IndexFieldSchema(
                        'price',
                        SortDirectionEnum::DESC,
                    )],
                ),
            ],
        ));

        $db->createTable(TableSchema::create(
            name: 'tags',
            uniqueConstraints: [],
            columns: [
                'id'         => 'int',
                'product_id' => 'int',
                'label'      => 'string',
            ],
            indexes: [
                new IndexSchema(
                    name: 'idx_product',
                    fields: [new IndexFieldSchema(
                        'product_id',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));
    }

    private static function seedCategories(JsonDataProvider $db): void
    {
        $names = [
            'Electronics',
            'Books',
            'Clothing',
            'Food',
            'Toys',
            'Sports',
            'Health',
            'Home',
            'Garden',
            'Automotive',
        ];

        foreach ($names as $i => $name) {
            $data = [
                'name' => $name,
                'sort' => ($i + 1) * 10,
            ];
            $id = $db->table('categories')->insertByArray($data);
            self::$categoriesFixture[] = ['id' => $id] + $data;
        }
    }

    private static function seedProducts(JsonDataProvider $db): void
    {
        $categoryCount = \count(self::$categoriesFixture);

        for ($i = 1; $i <= 100; $i++) {
            $categoryId = (($i - 1) % $categoryCount) + 1;
            $price = round(($i * 7.77) + 0.99, 2);
            $inStock = $i % 3 !== 0;

            $data = [
                'name'        => "Product {$i}",
                'category_id' => $categoryId,
                'price'       => $price,
                'in_stock'    => $inStock,
            ];
            $id = $db->table('products')->insertByArray($data);
            self::$productsFixture[] = ['id' => $id] + $data;
        }
    }

    private static function seedTags(JsonDataProvider $db): void
    {
        $labels = ['sale', 'new', 'hot', 'limited', 'bestseller'];

        foreach (self::$productsFixture as $i => $product) {
            $productId = $product['id'];
            \assert(\is_int($productId));

            $data = [
                'product_id' => $productId,
                'label'      => $labels[$i % \count($labels)],
            ];
            $id = $db->table('tags')->insertByArray($data);
            self::$tagsFixture[] = ['id' => $id] + $data;
        }
    }
}
