<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Bench;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Bench;

/**
 * What opening the database costs a request. Under PHP-FPM every request
 * builds its provider anew, while a benchmark process keeps one instance for
 * its whole run (getInstance() caches it per path), so no other suite sees
 * this cost.
 *
 * openDatabase drops the cached instance and opens the database again — the
 * work every request repeats; "cached" is the same getInstance() call
 * answered from the cache. Dropping the instance releases the previous one
 * inside the measured call; every engine version pays that alike, so a
 * comparison between versions stays fair.
 *
 * The database holds TABLES tables of ROWS rows, each table with a secondary
 * index, so the schema and the meta are of a realistic size.
 */
final class OpenBench
{
    private const string DB_PREFIX = 'jp-bench-open';

    private const int TABLES = 10;

    private const int ROWS = 100;

    private static string | null $path = null;

    private static \ReflectionProperty | null $instances = null;

    #[Bench(
        callables: ['cached' => [self::class, 'openCached']],
        warmup: 10,
        calls: 1000,
        iterations: 10,
        tolerance: INF,
    )]
    public static function openDatabase(): int
    {
        self::$instances ??= new \ReflectionProperty(
            JsonDataProvider::class,
            'instances',
        );
        self::$instances->setValue(null, []);
        JsonDataProvider::getInstance(self::dbPath());

        return 0;
    }

    public static function openCached(): int
    {
        JsonDataProvider::getInstance(self::dbPath());

        return 0;
    }

    private static function dbPath(): string
    {
        return self::$path ??= self::seed();
    }

    private static function seed(): string
    {
        $path = TempDir::root(self::DB_PREFIX);
        $db = JsonDataProvider::createDatabase($path);

        for ($t = 0; $t < self::TABLES; $t++) {
            $table = 'open_' . $t;
            $db->createTable(TableSchema::create(
                name: $table,
                columns: [
                    'title' => ColumnTypes::STRING,
                    'rank'  => ColumnTypes::INT,
                ],
                indexes: [new IndexSchema('idx_' . $table . '_rank', [
                    new IndexFieldSchema('rank', SortDirectionEnum::ASC),
                ])],
            ));

            $rows = [];

            for ($i = 1; $i <= self::ROWS; $i++) {
                $rows[] = [
                    'id'    => $i,
                    'title' => 'row' . $i,
                    'rank'  => $i % 10,
                ];
            }

            $db->importRecords($table, $rows);
        }

        return $path;
    }
}
