<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Mapping\DtoMap;
use AV\JsonProvider\Mapping\NameStrategy;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\Dto\AcronymDto;
use AV\JsonProvider\Tests\Support\Dto\DerivedMissDto;
use AV\JsonProvider\Tests\Support\Dto\InheritedPrivateDto;
use AV\JsonProvider\Tests\Support\Dto\IntersectionPropertyDto;
use AV\JsonProvider\Tests\Support\Dto\MixedPropertyDto;
use AV\JsonProvider\Tests\Support\Dto\UnionPropertyDto;
use AV\JsonProvider\Tests\Support\Dto\UntypedPropertyDto;
use AV\JsonProvider\Tests\Support\Dto\VisibilityDto;
use AV\JsonProvider\Tests\Support\Dto\VisibilityDtoTwin;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Compile-time and binding validation of DTOs:
 *  - promoted properties of any visibility map both ways;
 *  - binding two different classes to one table is a loud error;
 *  - acronym-aware camelCase → snake_case name derivation;
 *  - precise messages for untyped/union/intersection/mixed properties.
 */
final class DtoValidationTest
{
    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: 'dto_visibility',
            columns: ['id' => 'int', 'note' => 'string', 'tag' => 'string'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'dto_acronym',
            columns: [
                'id'          => 'int',
                'user_id'     => 'int',
                'http_status' => 'int',
            ],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function promotedPropertiesOfAnyVisibilityRoundTrip(): void
    {
        $this->db->registerDto(VisibilityDto::class);

        $id = $this->db->table('dto_visibility')->insert(
            new VisibilityDto(0, 'secret', 'green'),
        );

        $raw = $this->rawLine('dto_visibility', $id);
        Assert::notNull($raw);
        Assert::same($raw['note'], 'secret');
        Assert::same($raw['tag'], 'green');

        $dto = $this->db->table('dto_visibility')
            ->where('id', '=', $id)->selectOne();
        \assert($dto instanceof VisibilityDto);
        Assert::same($dto->note(), 'secret');
        Assert::same($dto->tag(), 'green');
    }

    #[Test]
    public function nonNullablePrivatePropertyGivesNoFalseNullError(): void
    {
        $this->db->registerDto(VisibilityDto::class);

        $id = $this->db->table('dto_visibility')->insert(
            new VisibilityDto(0, 'present', 'blue'),
        );

        Assert::int($id)->greaterThan(0);

        $raw = $this->rawLine('dto_visibility', $id);
        Assert::notNull($raw);
        Assert::same($raw['note'], 'present');
    }

    #[Test]
    public function registeringDifferentDtoOnSameTableThrows(): void
    {
        $this->db->registerDto(VisibilityDto::class);

        try {
            $this->db->registerDto(VisibilityDtoTwin::class);
            Assert::fail('a second DTO on the same table must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'DtoAlreadyRegistered');
            Assert::string($e->getMessage())->contains('VisibilityDto');
        }
    }

    /**
     * The point of unregisterDto: a table can be rebound to another class
     * once the live binding is released — and the new class really drives
     * hydration afterwards.
     */
    #[Test]
    public function unregisterDtoReleasesTheTableForAnotherClass(): void
    {
        $this->db->registerDto(VisibilityDto::class);
        $id = $this->db->table('dto_visibility')->insert(
            new VisibilityDto(0, 'note', 'tag'),
        );

        $same = $this->db->unregisterDto('dto_visibility');
        Assert::same($same, $this->db, 'unregisterDto must stay fluent');

        $this->db->registerDto(VisibilityDtoTwin::class);

        $row = $this->db->table('dto_visibility')
            ->where('id', '=', $id)->selectOne();
        Assert::instanceOf(
            $row,
            VisibilityDtoTwin::class,
            'the rebound class must drive hydration',
        );
        Assert::same($row->note(), 'note');
    }

    /**
     * Unbinding is process-local: the stored rows and the array surface
     * are untouched, only the object methods lose their map.
     */
    #[Test]
    public function unregisteredTableFallsBackToTheArraySurface(): void
    {
        $this->db->registerDto(VisibilityDto::class);
        $id = $this->db->table('dto_visibility')->insert(
            new VisibilityDto(0, 'kept', 'tag'),
        );

        $this->db->unregisterDto('dto_visibility');

        $row = $this->db->table('dto_visibility')
            ->where('id', '=', $id)->selectOneByArray();
        \assert(\is_array($row));
        Assert::same($row['note'], 'kept');

        try {
            $this->db->table('dto_visibility')->selectOne();
            Assert::fail('object methods must fail without a binding');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'DtoNotRegistered');
        }
    }

    #[Test]
    public function unregisterDtoIsLoudOnNothingToUnbind(): void
    {
        try {
            $this->db->unregisterDto('dto_visibility');
            Assert::fail('unbinding an unbound table must be loud');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'DtoNotRegistered');
        }
    }

    /**
     * The argument is a table name, so a class-string cannot be mistaken
     * for one and silently unbind nothing.
     */
    #[Test]
    public function unregisterDtoRejectsAClassString(): void
    {
        $this->db->registerDto(VisibilityDto::class);

        try {
            $this->db->unregisterDto(VisibilityDto::class);
            Assert::fail('a class-string is not a table name');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'InvalidTableName');
        }

        $row = $this->db->table('dto_visibility')->selectOne();
        Assert::null($row, 'the binding must survive the rejected call');
    }

    #[Test]
    public function reRegisteringSameDtoIsIdempotent(): void
    {
        $this->db->registerDto(VisibilityDto::class);
        $this->db->registerDto(VisibilityDto::class);

        $id = $this->db->table('dto_visibility')->insert(
            new VisibilityDto(0, 'x', 'y'),
        );
        Assert::int($id)->greaterThan(0);
    }

    #[Test]
    public function acronymPropertiesDeriveColumnsWithoutOverride(): void
    {
        $this->db->registerDto(AcronymDto::class);

        $id = $this->db->table('dto_acronym')->insert(
            new AcronymDto(0, 42, 200),
        );

        $raw = $this->rawLine('dto_acronym', $id);
        Assert::notNull($raw);
        Assert::same($raw['user_id'], 42);
        Assert::same($raw['http_status'], 200);
    }

    #[Test]
    public function nameStrategyHandlesAcronymRuns(): void
    {
        Assert::same(NameStrategy::columnFor('HTTPStatus'), 'http_status');
        Assert::same(NameStrategy::columnFor('userID'), 'user_id');
        Assert::same(NameStrategy::columnFor('createdAt'), 'created_at');
        Assert::same(NameStrategy::columnFor('id'), 'id');
        Assert::same(NameStrategy::columnFor('APIKey'), 'api_key');
        Assert::same(
            NameStrategy::columnFor('HTTPStatusCode'),
            'http_status_code',
        );
    }

    #[Test]
    public function derivedColumnMissMentionsPropertyAndOverrideHint(): void
    {
        $schema = TableSchema::create(
            name: 'dto_derived_miss',
            columns: ['id' => 'int'],
        );

        try {
            DtoMap::compile(DerivedMissDto::class, $schema);
            Assert::fail('a missing derived column must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'DtoPropertyColumnDerivedMiss');
            Assert::string($e->getMessage())->contains('userName');
            Assert::string($e->getMessage())->contains('user_name');
            Assert::string($e->getMessage())->contains('JsonProviderColumn');
        }
    }

    #[Test]
    public function untypedPropertyIsRejectedWithClearMessage(): void
    {
        $schema = TableSchema::create(
            name: 'dto_untyped',
            columns: ['id' => 'int', 'x' => 'string'],
        );

        try {
            DtoMap::compile(UntypedPropertyDto::class, $schema);
            Assert::fail('an untyped property must be rejected');
        } catch (JsonProviderException $e) {
            Assert::string($e->getMessage())->contains('no type declaration');
        }
    }

    #[Test]
    public function unionTypeIsRejectedWithClearMessage(): void
    {
        $schema = TableSchema::create(
            name: 'dto_union',
            columns: ['id' => 'int', 'x' => 'string'],
        );

        try {
            DtoMap::compile(UnionPropertyDto::class, $schema);
            Assert::fail('a union type must be rejected');
        } catch (JsonProviderException $e) {
            Assert::string($e->getMessage())->contains('union types');
        }
    }

    #[Test]
    public function intersectionTypeIsRejectedWithClearMessage(): void
    {
        $schema = TableSchema::create(
            name: 'dto_intersection',
            columns: ['id' => 'int', 'x' => 'string'],
        );

        try {
            DtoMap::compile(IntersectionPropertyDto::class, $schema);
            Assert::fail('an intersection type must be rejected');
        } catch (JsonProviderException $e) {
            Assert::string($e->getMessage())->contains('intersection types');
        }
    }

    #[Test]
    public function mixedTypeIsRejectedBeforeNullability(): void
    {
        $schema = TableSchema::create(
            name: 'dto_mixed',
            columns: ['id' => 'int', 'x' => 'string'],
        );

        try {
            DtoMap::compile(MixedPropertyDto::class, $schema);
            Assert::fail('a mixed type must be rejected');
        } catch (JsonProviderException $e) {
            Assert::string($e->getMessage())->contains('mixed');
            Assert::false(
                str_contains($e->getMessage(), 'nullability'),
                'mixed must be reported as a type error, not a nullability '
                    . 'one: ' . $e->getMessage(),
            );
        }
    }

    #[Test]
    public function inheritedPrivatePropertyIsRejectedNotSilentlyLost(): void
    {
        $schema = TableSchema::create(
            name: 'inh_priv',
            columns: ['id' => 'int', 'secret' => 'string|null'],
        );

        try {
            DtoMap::compile(InheritedPrivateDto::class, $schema);
            Assert::fail(
                'a private property inherited from a parent class is '
                    . 'unreadable by the mapper and must be rejected '
                    . 'at compile time, not silently written as null',
            );
        } catch (JsonProviderException $e) {
            $message = $e->getMessage();
            Assert::same($e->getErrorKey(), 'DtoPropertyInheritedPrivate');
            Assert::string($message)->contains('secret');
            Assert::string($message)->contains('InheritedPrivateBase');
        }
    }

    /**
     * @return null|array<string,null|scalar>
     */
    private function rawLine(
        string $table,
        int $id,
    ): array | null {
        $path = $this->dbDir . '/' . $table . '/' . $table . '.ndjson';
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        foreach (explode("\n", trim($contents)) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (!\is_array($decoded) || ($decoded['id'] ?? null) !== $id) {
                continue;
            }

            $row = [];

            foreach ($decoded as $k => $v) {
                if (\is_string($k) && (\is_scalar($v) || $v === null)) {
                    $row[$k] = $v;
                }
            }

            return $row;
        }

        return null;
    }

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

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-dto-validation-tests');
    }
}
