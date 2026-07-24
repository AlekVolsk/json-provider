<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for unique-key semantics:
 *
 *  - SQL NULL rule: a record with null (or a missing field) in any
 *    constraint field never participates — any number of such records
 *    coexist, on single and composite constraints alike;
 *  - type-strict keys: 1, 1.0, '1' and true are four distinct values that
 *    never conflict with each other (no SQL-style numeric merging);
 *  - the int/float boundary inside a declared float column is closed by
 *    the storage format instead: an int is widened to float on write and
 *    on read, so 1 and 1.0 there are the same key;
 *  - keyOf/keyPart canonicalization as the single uniqueness codec.
 */
final class UniqueConstraintTest
{
    private const string DB_PATH = '/tmp/jp-unique-tests';

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::DB_PATH);

        $this->dbDir = self::DB_PATH . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: 'u_code',
            uniqueConstraints: [new UniqueConstraint('uq_code', ['code'])],
            columns: ['id' => 'int', 'code' => 'string|null'],
            indexes: [],
        ));

        $this->db->createTable(TableSchema::create(
            name: 'u_pair',
            uniqueConstraints: [new UniqueConstraint('uq_ab', ['a', 'b'])],
            columns: [
                'id' => 'int',
                'a'  => 'int|null',
                'b'  => 'int|null',
            ],
            indexes: [],
        ));

        $this->db->createTable(TableSchema::create(
            name: 'u_float',
            uniqueConstraints: [new UniqueConstraint('uq_price', ['price'])],
            columns: ['id' => 'int', 'price' => 'float|null'],
            indexes: [],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::DB_PATH);
    }

    // -- NULL never participates -------------------------------------------

    #[Test]
    public function twoNullsPass(): void
    {
        $this->db->insert('u_code', ['code' => null]);
        $this->db->insert('u_code', ['code' => null]);

        Assert::same($this->db->table('u_code')->count(), 2);
    }

    #[Test]
    public function nullAndEmptyStringPass(): void
    {
        $this->db->insert('u_code', ['code' => null]);
        $this->db->insert('u_code', ['code' => '']);

        Assert::same($this->db->table('u_code')->count(), 2);
    }

    #[Test]
    public function emptyStringTwiceViolates(): void
    {
        $this->db->insert('u_code', ['code' => '']);

        try {
            $this->db->insert('u_code', ['code' => '']);
            Assert::fail('a StorageException was expected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'UNIQUE_VIOLATION');
            Assert::string($e->getMessage())->contains('[code]');
        }
    }

    #[Test]
    public function duplicateStringViolates(): void
    {
        $this->db->insert('u_code', ['code' => 'x']);

        Expect::exception(StorageException::class)
            ->withMessageContaining('[code]');

        $this->db->insert('u_code', ['code' => 'x']);
    }

    // -- type-strict keys ---------------------------------------------------
    //
    // The schema boundary rejects unknown column types, so one column can
    // hold mixed scalar types only through foreign tampering with the data
    // file. The key codec stays type-strict regardless of how the values
    // got there: keyOf-level units pin the four-way distinction, and the
    // tampering test pins the disk roundtrip.

    #[Test]
    public function fourScalarTypesProduceFourDistinctKeys(): void
    {
        $constraint = new UniqueConstraint('uq_v', ['v']);

        $keys = [
            $constraint->keyOf(['v' => 1]),
            $constraint->keyOf(['v' => 1.0]),
            $constraint->keyOf(['v' => '1']),
            $constraint->keyOf(['v' => true]),
        ];

        Assert::count(array_unique($keys), 4);
        Assert::same(
            $constraint->keyOf(['v' => 1]),
            $constraint->keyOf(['v' => 1]),
        );
    }

    #[Test]
    public function tamperedCrossTypeRowDoesNotConflictWithString(): void
    {
        $this->db->insert('u_code', ['code' => 'x']);

        file_put_contents(
            $this->dbDir . '/u_code/u_code.ndjson',
            '{"id":90,"code":1}' . "\n",
            FILE_APPEND,
        );
        $this->db->invalidateCache('u_code');

        $id = $this->db->insert('u_code', ['code' => '1']);

        Assert::int($id)->greaterThan(0);
        Assert::same($this->db->table('u_code')->count(), 3);
    }

    // -- float column: storage format merges the int/float boundary ---------

    #[Test]
    public function sameFloatTwiceViolates(): void
    {
        $this->db->insert('u_float', ['price' => 1.5]);

        Expect::exception(StorageException::class)
            ->withMessageContaining('[price]');

        $this->db->insert('u_float', ['price' => 1.5]);
    }

    #[Test]
    public function intWidensIntoFloatUniqueKey(): void
    {
        $this->db->insert('u_float', ['price' => 1.0]);

        Expect::exception(StorageException::class)
            ->withMessageContaining('[price]');

        $this->db->insert('u_float', ['price' => 1]);
    }

    #[Test]
    public function legacyIntRowConflictsWithFloatInsert(): void
    {
        $this->db->insert('u_float', ['price' => 7.0]);

        $path = $this->dbDir . '/u_float/u_float.ndjson';
        $raw = (string)file_get_contents($path);
        $patched = str_replace('"price":7.0', '"price":7', $raw);
        Assert::true($patched !== $raw);
        file_put_contents($path, $patched);
        $this->db->invalidateCache('u_float');

        Expect::exception(StorageException::class)
            ->withMessageContaining('[price]');

        $this->db->insert('u_float', ['price' => 7.0]);
    }

    // -- composite constraints ----------------------------------------------

    #[Test]
    public function compositeWithNullFieldNeverConflicts(): void
    {
        $this->db->insert('u_pair', ['a' => 1, 'b' => null]);
        $this->db->insert('u_pair', ['a' => 1, 'b' => null]);
        $this->db->insert('u_pair', ['a' => null, 'b' => null]);

        Assert::same($this->db->table('u_pair')->count(), 3);
    }

    #[Test]
    public function compositeDuplicateViolates(): void
    {
        $this->db->insert('u_pair', ['a' => 1, 'b' => 2]);
        $this->db->insert('u_pair', ['a' => 1, 'b' => 3]);

        Expect::exception(StorageException::class)
            ->withMessageContaining('[a, b]');

        $this->db->insert('u_pair', ['a' => 1, 'b' => 2]);
    }

    // -- update paths --------------------------------------------------------

    #[Test]
    public function updateToConflictingValueViolates(): void
    {
        $this->db->insert('u_code', ['code' => 'a']);
        $idB = $this->db->insert('u_code', ['code' => 'b']);

        Expect::exception(StorageException::class)
            ->withMessageContaining('[code]');

        $this->db->table('u_code')->updateByIdByArray($idB, ['code' => 'a']);
    }

    #[Test]
    public function updateKeepingOwnValuePasses(): void
    {
        $id = $this->db->insert('u_code', ['code' => 'keep']);

        $ok = $this->db->table('u_code')
            ->updateByIdByArray($id, ['code' => 'keep']);

        Assert::true($ok);
    }

    #[Test]
    public function updateToNullAlwaysPasses(): void
    {
        $idA = $this->db->insert('u_code', ['code' => 'a']);
        $idB = $this->db->insert('u_code', ['code' => 'b']);

        Assert::true(
            $this->db->table('u_code')->updateByIdByArray(
                $idA,
                ['code' => null],
            ),
        );
        Assert::true(
            $this->db->table('u_code')->updateByIdByArray(
                $idB,
                ['code' => null],
            ),
        );
        Assert::same($this->db->table('u_code')->count(), 2);
    }

    // -- ghost rows -----------------------------------------------------------

    #[Test]
    public function recordMissingConstraintFieldIsSkipped(): void
    {
        $this->db->insert('u_code', ['code' => 'real']);

        file_put_contents(
            $this->dbDir . '/u_code/u_code.ndjson',
            '{"id":77}' . "\n",
            FILE_APPEND,
        );
        $this->db->invalidateCache('u_code');

        $id = $this->db->insert('u_code', ['code' => 'fresh']);

        Assert::int($id)->greaterThan(0);
    }

    // -- keyOf / keyPart units ------------------------------------------------

    #[Test]
    public function keyPartDistinguishesScalarTypes(): void
    {
        Assert::same(UniqueConstraint::keyPart(1), 'n:1');
        Assert::same(UniqueConstraint::keyPart(1.0), 'f:1');
        Assert::same(UniqueConstraint::keyPart('1'), 's:1');
        Assert::same(UniqueConstraint::keyPart(true), 'b:1');
        Assert::same(UniqueConstraint::keyPart(false), 'b:0');

        $parts = [
            UniqueConstraint::keyPart(1),
            UniqueConstraint::keyPart(1.0),
            UniqueConstraint::keyPart('1'),
            UniqueConstraint::keyPart(true),
        ];
        Assert::count(array_unique($parts), 4);
    }

    #[Test]
    public function keyPartCanonicalizesFloats(): void
    {
        Assert::same(UniqueConstraint::keyPart(0.5), 'f:0.5');
        Assert::same(
            UniqueConstraint::keyPart(0.1),
            'f:' . \sprintf('%.17G', 0.1),
        );
        Assert::true(
            UniqueConstraint::keyPart(0.1 + 0.2)
                !== UniqueConstraint::keyPart(0.3),
        );
    }

    #[Test]
    public function keyPartFoldsNegativeZeroIntoZero(): void
    {
        Assert::same(UniqueConstraint::keyPart(-0.0), 'f:0');
        Assert::same(
            UniqueConstraint::keyPart(-0.0),
            UniqueConstraint::keyPart(0.0),
        );
    }

    #[Test]
    public function negativeZeroConflictsWithZero(): void
    {
        $this->db->insert('u_float', ['price' => 0.0]);

        Expect::exception(StorageException::class)
            ->withMessageContaining('[price]');

        $this->db->insert('u_float', ['price' => -0.0]);
    }

    #[Test]
    public function keyOfNullWhenAnyFieldNullOrMissing(): void
    {
        $constraint = new UniqueConstraint('u', ['a', 'b']);

        Assert::null($constraint->keyOf(['a' => 1]));
        Assert::null($constraint->keyOf(['a' => 1, 'b' => null]));
        Assert::null($constraint->keyOf([]));
        Assert::same(
            $constraint->keyOf(['a' => 1, 'b' => 2]),
            "3:n:1\x003:n:2",
        );
    }

    #[Test]
    public function keyOfIsInjectiveForNulBearingStrings(): void
    {
        $constraint = new UniqueConstraint('u', ['f1', 'f2']);

        $keyA = $constraint->keyOf(['f1' => "a\x00s:b", 'f2' => 'c']);
        $keyB = $constraint->keyOf(['f1' => 'a', 'f2' => "b\x00s:c"]);

        Assert::notNull($keyA);
        Assert::notNull($keyB);
        Assert::true($keyA !== $keyB);
    }

    #[Test]
    public function nulBearingTuplesDoNotFalselyConflict(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'u_nul',
            uniqueConstraints: [new UniqueConstraint('uq_fg', ['f', 'g'])],
            columns: [
                'id' => 'int',
                'f'  => 'string',
                'g'  => 'string',
            ],
            indexes: [],
        ));

        $this->db->insert('u_nul', ['f' => "a\x00s:b", 'g' => 'c']);
        $this->db->insert('u_nul', ['f' => 'a', 'g' => "b\x00s:c"]);

        Assert::same($this->db->table('u_nul')->count(), 2);
    }

    // -- helpers -----------------------------------------------------------

    private function removeDir(string $path): void
    {
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
}
