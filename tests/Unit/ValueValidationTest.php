<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

/**
 * Tests for strict, schema-driven value validation of the primitive column
 * types (string/int/float/bool) and their nullable variants.
 *
 * Contract: a value must match the declared PHP type exactly; the one widening
 * allowed is int into a float column. null is accepted only on `<type>|null`
 * columns. Unknown keys are dropped. Update validates only the keys it is
 * given and leaves the rest of the record untouched.
 */
final class ValueValidationTest
{
    private const string TABLE = 'typed';

    private static bool $booted = false;

    #[Test]
    public function rejectsStringInIntColumn(): void
    {
        $db = self::db();

        Expect::exception(StorageException::class)
            ->withMessageContaining('expected type int');

        $db->table(self::TABLE)->insertByArray(self::row(['n' => '42']));
    }

    #[Test]
    public function rejectsIntInStringColumn(): void
    {
        $db = self::db();

        Expect::exception(StorageException::class)
            ->withMessageContaining('expected type string');

        $db->table(self::TABLE)->insertByArray(self::row(['s' => 5]));
    }

    #[Test]
    public function rejectsIntInBoolColumn(): void
    {
        $db = self::db();

        Expect::exception(StorageException::class)
            ->withMessageContaining('expected type bool');

        $db->table(self::TABLE)->insertByArray(self::row(['b' => 1]));
    }

    #[Test]
    public function rejectsStringInFloatColumn(): void
    {
        $db = self::db();

        Expect::exception(StorageException::class)
            ->withMessageContaining('expected type float');

        $db->table(self::TABLE)->insertByArray(self::row(['f' => '1.5']));
    }

    #[Test]
    public function widensIntIntoFloatColumn(): void
    {
        $db = self::db();

        $id = $db->table(self::TABLE)->insertByArray(self::row(['f' => 3]));
        Assert::int($id)->greaterThan(0);

        $row = $db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::same((float)$row['f'], 3.0);

        $db->table(self::TABLE)->deleteById($id);
    }

    #[Test]
    public function acceptsNullOnNullableColumn(): void
    {
        $db = self::db();

        $id = $db->table(self::TABLE)
            ->insertByArray(self::row(['opt' => null]));

        $row = $db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::null($row['opt']);

        $db->table(self::TABLE)->deleteById($id);
    }

    #[Test]
    public function rejectsNullOnNonNullableColumn(): void
    {
        $db = self::db();

        Expect::exception(StorageException::class)
            ->withMessageContaining('not nullable');

        $db->table(self::TABLE)->insertByArray(self::row(['s' => null]));
    }

    #[Test]
    public function dropsUnknownColumnsOnInsert(): void
    {
        $db = self::db();

        $id = $db->table(self::TABLE)->insertByArray(
            self::row(['ghost' => 'x', 'other' => 7]),
        );

        $row = $db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::same(
            array_keys($row),
            ['id', 's', 'n', 'f', 'b', 'opt'],
        );

        $db->table(self::TABLE)->deleteById($id);
    }

    #[Test]
    public function updateValidatesProvidedValue(): void
    {
        $db = self::db();

        $id = $db->table(self::TABLE)->insertByArray(self::row());

        try {
            Expect::exception(StorageException::class)
                ->withMessageContaining('expected type int');

            $db->table(self::TABLE)
                ->where('id', '=', $id)
                ->updateByArray(['n' => 'not-an-int']);
        } finally {
            $db->table(self::TABLE)->deleteById($id);
        }
    }

    #[Test]
    public function updateLeavesUnprovidedColumnsUntouched(): void
    {
        $db = self::db();

        $id = $db->table(self::TABLE)->insertByArray(
            self::row(['s' => 'before', 'n' => 10]),
        );

        $db->table(self::TABLE)
            ->where('id', '=', $id)
            ->updateByArray(['s' => 'after']);

        $row = $db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::same($row['s'], 'after');
        Assert::same($row['n'], 10);

        $db->table(self::TABLE)->deleteById($id);
    }

    /**
     * A complete, all-valid row with the given overrides applied on top.
     *
     * @param array<string,null|scalar> $overrides
     *
     * @return array<string,null|scalar>
     */
    private static function row(array $overrides = []): array
    {
        return array_merge([
            's' => 'text',
            'n' => 1,
            'f' => 1.5,
            'b' => true,
        ], $overrides);
    }

    private static function db(): JsonDataProvider
    {
        if (self::$booted) {
            return JsonDataProvider::getInstance(self::dbPathRoot());
        }

        self::wipe();
        $db = JsonDataProvider::createDatabase(self::dbPathRoot());
        $db->createTable(TableSchema::create(
            name: self::TABLE,
            columns: [
                'id'  => 'int',
                's'   => 'string',
                'n'   => 'int',
                'f'   => 'float',
                'b'   => 'bool',
                'opt' => 'string|null',
            ],
        ));
        self::$booted = true;

        return $db;
    }

    private static function wipe(): void
    {
        if (!is_dir(self::dbPathRoot())) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                self::dbPathRoot(),
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir()
                ? rmdir($file->getPathname())
                : unlink($file->getPathname());
        }

        rmdir(self::dbPathRoot());
    }

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-validation-tests');
    }
}
