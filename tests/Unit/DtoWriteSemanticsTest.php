<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\JsonTable;
use AV\JsonProvider\Mapping\MissingPropertyModeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\Dto\ContactDto;
use AV\JsonProvider\Tests\Support\Dto\PhoneOnlyDto;
use AV\JsonProvider\Tests\Support\Dto\PhoneUserDto;
use AV\JsonProvider\Tests\Support\Dto\StampDto;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * How object writes turn a DTO into a stored row:
 *  - a DateTimeImmutable is truncated to the column precision;
 *  - objects are duck-typed: a mapped property the object lacks is null on
 *    insert, and on update either null (WriteNull) or the stored value
 *    (KeepStored) — null still goes through the nullability check;
 *  - update($dto) selects the row by the DTO's id and ignores where(),
 *    while updateByArray() selects by where() and drops an id key;
 *  - an object method on a table without a DTO resets the builder state
 *    like any other failed terminal operation.
 */
final class DtoWriteSemanticsTest
{
    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = JsonDataProvider::createDatabase(
            self::root() . '/' . uniqid('db', true),
        );
        $this->db->createTable(TableSchema::create(
            name: 'dto_stamps',
            columns: ['at' => 'datetime', 'atz' => 'datetimez'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'dto_phone_users',
            columns: ['email' => 'string', 'phone' => 'string|null'],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'plain',
            columns: ['s' => 'string'],
        ));
        $this->db->registerDto(StampDto::class);
        $this->db->registerDto(PhoneUserDto::class);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove(self::root());
    }

    #[Test]
    public function dateTimeIsTruncatedToColumnPrecision(): void
    {
        $value = new \DateTimeImmutable('2026-01-01 10:00:59.999999');
        $id = $this->db->table('dto_stamps')
            ->insert(new StampDto(0, $value, $value));

        $row = $this->db->table('dto_stamps')
            ->where('id', '=', $id)
            ->selectOne();

        Assert::true($row instanceof StampDto);
        Assert::same($row->at->format('H:i:s.u'), '10:00:59.000000');
        Assert::same($row->atz->format('H:i:s.u'), '10:00:59.999000');
    }

    #[Test]
    public function updateWritesNullForMissingPropertyByDefault(): void
    {
        $id = $this->insertUser();

        $this->users()->update(new ContactDto($id, 'b@x', '+7911'));

        Assert::same($this->row($id), [
            'id'    => $id,
            'email' => 'b@x',
            'phone' => null,
        ]);
    }

    #[Test]
    public function updateKeepsStoredValueForMissingPropertyOnRequest(): void
    {
        $id = $this->insertUser();

        $this->users()->update(
            new ContactDto($id, 'c@x', '+7911'),
            MissingPropertyModeEnum::KeepStored,
        );
        Assert::same($this->row($id)['phone'], '+7900');

        $this->users()->update(
            new PhoneOnlyDto($id, '+1'),
            MissingPropertyModeEnum::KeepStored,
        );
        Assert::same($this->row($id), [
            'id'    => $id,
            'email' => 'c@x',
            'phone' => '+1',
        ]);

        $this->users()->update(
            new PhoneUserDto($id, 'd@x', null),
            MissingPropertyModeEnum::KeepStored,
        );
        Assert::same($this->row($id)['phone'], null);
    }

    #[Test]
    public function uninitializedPropertyCountsAsMissing(): void
    {
        $id = $this->insertUser();
        $partial = new class {
            public int $id;

            public string $email;

            public string | null $phone;
        };
        $partial->id = $id;
        $partial->phone = '+2';

        $this->users()->update($partial, MissingPropertyModeEnum::KeepStored);

        Assert::same($this->row($id), [
            'id'    => $id,
            'email' => 'a@x',
            'phone' => '+2',
        ]);
    }

    #[Test]
    public function missingNotNullPropertyIsRejected(): void
    {
        $id = $this->insertUser();

        $this->expectErrorKey(
            'NullNotAllowed',
            fn () => $this->users()->update(new PhoneOnlyDto($id, '+1')),
        );
        $this->expectErrorKey(
            'NullNotAllowed',
            fn () => $this->users()->insert(new PhoneOnlyDto(0, '+1')),
        );

        $contactId = $this->users()->insert(new ContactDto(0, 'e@x', '+7'));
        Assert::same($this->row($contactId)['phone'], null);
    }

    #[Test]
    public function updateByDtoSelectsTheRowByIdAndIgnoresWhere(): void
    {
        $first = $this->insertUser();
        $second = $this->users()->insert(new PhoneUserDto(0, 'z@x', null));

        $table = $this->users();
        $table->where('id', '=', $second)
            ->update(new PhoneUserDto($first, 'dto@x', null));

        Assert::same($table->affectedRows(), 1);
        Assert::same($this->row($first)['email'], 'dto@x');
        Assert::same($this->row($second)['email'], 'z@x');
    }

    #[Test]
    public function updateByArrayWithoutWhereUpdatesEveryRowKeepingIds(): void
    {
        $first = $this->insertUser();
        $second = $this->insertUser();

        $table = $this->users();
        $table->updateByArray(['email' => 'all@x', 'id' => 99]);

        Assert::same($table->affectedRows(), 2);
        Assert::same(
            $this->users()->selectColumn('id'),
            [$first, $second],
        );
        Assert::same(
            $this->users()->selectColumn('email'),
            ['all@x', 'all@x'],
        );
    }

    #[Test]
    public function objectMethodWithoutDtoResetsBuilderState(): void
    {
        foreach (['a', 'b', 'c'] as $value) {
            $this->db->insert('plain', ['s' => $value]);
        }

        $table = $this->db->table('plain');
        $calls = [
            'selectAll' => static fn () => iterator_to_array(
                $table->selectAll(),
            ),
            'selectOne' => static fn () => $table->selectOne(),
            'insert'    => static fn () => $table->insert(new \stdClass()),
            'update'    => static fn () => $table->update(new \stdClass()),
        ];

        foreach ($calls as $name => $call) {
            $table->where('s', '=', 'a')
                ->orderBy('s', 'desc')
                ->limit(1)
                ->offset(1);

            try {
                $call();
                Assert::fail($name . ' must require a DTO');
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'DtoNotRegistered', $name);
            }

            Assert::same(\count($table->selectAllByArray()), 3, $name);
        }
    }

    private function insertUser(): int
    {
        return $this->users()->insert(new PhoneUserDto(0, 'a@x', '+7900'));
    }

    private function users(): JsonTable
    {
        return $this->db->table('dto_phone_users');
    }

    /**
     * @return array<string,null|scalar>
     */
    private function row(int $id): array
    {
        $row = $this->db->table('dto_phone_users')
            ->where('id', '=', $id)
            ->selectOneByArray();
        Assert::notNull($row);

        return $row;
    }

    private function expectErrorKey(string $key, callable $call): void
    {
        try {
            $call();
            Assert::fail('expected ' . $key);
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), $key);
        }
    }

    private static function root(): string
    {
        return TempDir::root('jp-dto-write-tests');
    }
}
