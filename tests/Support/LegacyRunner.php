<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;

/**
 * Child-process side of the compatibility tests: applies a list of
 * operations to one database and prints their results as JSON.
 *
 * The same class drives either engine. EngineProcess decides which one is
 * loaded before this class runs: for the legacy side it prepends an
 * autoloader that resolves the library namespace to the extracted v1.0.0
 * sources, so every library class this runner touches comes from there.
 * The runner therefore uses only API both versions share — except the
 * operations named after newer API ("migrate"), which only the current
 * side may run.
 *
 * Each operation is an object with an "op" key; a library exception
 * becomes {"error": "<case name>"} in place of the result, so a test can
 * assert on refusals; any other failure ends the process non-zero.
 */
final class LegacyRunner
{
    public static function main(string $dbDir, string $opsJson): int
    {
        $ops = json_decode($opsJson, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($ops)) {
            throw new \InvalidArgumentException('operations must be a list');
        }

        $results = [];

        foreach ($ops as $op) {
            if (!\is_array($op)) {
                throw new \InvalidArgumentException('operation must be a map');
            }

            try {
                $results[] = self::apply($dbDir, $op);
            } catch (JsonProviderException $e) {
                $results[] = ['error' => $e->error->name];
            }
        }

        echo json_encode(
            ['results' => $results],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );

        return 0;
    }

    /**
     * @param array<mixed> $op
     */
    private static function apply(string $dbDir, array $op): mixed
    {
        return match (self::str($op, 'op')) {
            'engine'      => self::engineFile(),
            'initNotices' => self::initNotices($dbDir),
            'migrate'     => self::db($dbDir)->migrateStorage()->steps,
            'create'      => self::create($dbDir),
            'insert'      => self::db($dbDir)
                ->table(self::str($op, 'table'))
                ->insertByArray(self::record($op, 'record')),
            'update' => self::db($dbDir)
                ->table(self::str($op, 'table'))
                ->updateByIdByArray(
                    self::int($op, 'id'),
                    self::record($op, 'patch'),
                ),
            'delete' => self::db($dbDir)
                ->table(self::str($op, 'table'))
                ->deleteById(self::int($op, 'id')),
            'rows' => self::db($dbDir)
                ->table(self::str($op, 'table'))
                ->orderBy('id')
                ->selectAllByArray(),
            'where' => self::db($dbDir)
                ->table(self::str($op, 'table'))
                ->where(self::str($op, 'field'), '=', $op['value'] ?? null)
                ->orderBy('id')
                ->selectAllByArray(),
            'validate'    => self::issues($dbDir, false),
            'repair'      => self::issues($dbDir, true),
            'rebuild'     => self::rebuild($dbDir, self::str($op, 'table')),
            'backup'      => self::db($dbDir)->backup(self::str($op, 'dest')),
            'restore'     => self::restore($dbDir, self::str($op, 'archive')),
            'createExtra' => self::createExtra($dbDir),
            'dropTable'   => self::dropTable($dbDir, self::str($op, 'table')),
            'renameTable' => self::renameTable(
                $dbDir,
                self::str($op, 'from'),
                self::str($op, 'to'),
            ),
            'addIndex' => self::addIndex(
                $dbDir,
                self::str($op, 'table'),
                self::str($op, 'name'),
                self::str($op, 'field'),
            ),
            'insertMany' => self::insertMany(
                $dbDir,
                self::int($op, 'count'),
                self::str($op, 'tag'),
            ),
            default => throw new \InvalidArgumentException(
                'unknown operation',
            ),
        };
    }

    private static function db(string $dbDir): JsonDataProvider
    {
        return JsonDataProvider::getInstance($dbDir);
    }

    private static function engineFile(): string
    {
        return (string)(new \ReflectionClass(JsonDataProvider::class))
            ->getFileName();
    }

    /**
     * Opens the database and returns the PHP user notices the
     * initialization raised, as "deprecated|<text>" or "warning|<text>".
     * Meaningful only as the first operation of a process: later ones
     * reuse the cached instance.
     *
     * @return list<string>
     */
    private static function initNotices(string $dbDir): array
    {
        $notices = [];

        set_error_handler(
            static function (
                int $level,
                string $message,
            ) use (&$notices): bool {
                $notices[] = match ($level) {
                    E_USER_DEPRECATED => 'deprecated',
                    E_USER_WARNING    => 'warning',
                    default           => (string)$level,
                } . '|' . $message;

                return true;
            },
        );

        try {
            JsonDataProvider::getInstance($dbDir);
        } finally {
            restore_error_handler();
        }

        return $notices;
    }

    private static function create(string $dbDir): bool
    {
        $db = JsonDataProvider::createDatabase($dbDir);
        CompatFixture::build($db);
        CompatFixture::seed($db);

        return true;
    }

    /**
     * Issues of a validate or repair run as "severity|category|table".
     *
     * @return list<string>
     */
    private static function issues(string $dbDir, bool $repair): array
    {
        $db = self::db($dbDir);
        $report = $repair ? $db->repair() : $db->validate();
        $issues = [];

        foreach ($report->issues as $issue) {
            $issues[] = $issue->severity->value . '|'
                . $issue->category->value . '|'
                . ($issue->tableName ?? '');
        }

        return $issues;
    }

    private static function rebuild(string $dbDir, string $table): bool
    {
        self::db($dbDir)->rebuildAllIndexes($table);

        return true;
    }

    private static function restore(string $dbDir, string $archive): bool
    {
        self::db($dbDir)->restore($archive);

        return true;
    }

    private static function createExtra(string $dbDir): bool
    {
        self::db($dbDir)->createTable(CompatFixture::extraTable());

        return true;
    }

    private static function dropTable(string $dbDir, string $table): bool
    {
        self::db($dbDir)->dropTable($table);

        return true;
    }

    private static function renameTable(
        string $dbDir,
        string $from,
        string $to,
    ): bool {
        self::db($dbDir)->renameTable($from, $to);

        return true;
    }

    private static function addIndex(
        string $dbDir,
        string $table,
        string $name,
        string $field,
    ): bool {
        self::db($dbDir)->addIndex($table, new IndexSchema(
            $name,
            [new IndexFieldSchema($field, SortDirectionEnum::ASC)],
        ));

        return true;
    }

    /**
     * Inserts $count items of the first owner one by one — each insert is
     * its own locked write, so a concurrent writer interleaves with them.
     */
    private static function insertMany(
        string $dbDir,
        int $count,
        string $tag,
    ): int {
        $table = self::db($dbDir)->table(CompatFixture::ITEMS);

        for ($i = 0; $i < $count; $i++) {
            $table->insertByArray([
                'ownerId' => 1,
                'title'   => $tag . '-' . $i,
                'qty'     => $i,
            ]);
        }

        return $count;
    }

    /**
     * @param array<mixed> $op
     */
    private static function str(array $op, string $key): string
    {
        $value = $op[$key] ?? null;

        if (!\is_string($value)) {
            throw new \InvalidArgumentException($key . ' must be a string');
        }

        return $value;
    }

    /**
     * @param array<mixed> $op
     */
    private static function int(array $op, string $key): int
    {
        $value = $op[$key] ?? null;

        if (!\is_int($value)) {
            throw new \InvalidArgumentException($key . ' must be an int');
        }

        return $value;
    }

    /**
     * @param array<mixed> $op
     *
     * @return array<string,null|scalar>
     */
    private static function record(array $op, string $key): array
    {
        $value = $op[$key] ?? null;

        if (!\is_array($value)) {
            throw new \InvalidArgumentException($key . ' must be a map');
        }

        $record = [];

        foreach ($value as $field => $cell) {
            if (!\is_string($field) || !(\is_scalar($cell) || $cell === null)) {
                throw new \InvalidArgumentException($key . ' is malformed');
            }

            $record[$field] = $cell;
        }

        return $record;
    }
}
