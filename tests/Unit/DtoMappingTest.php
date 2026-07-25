<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\Dto\BadYearDto;
use AV\JsonProvider\Tests\Support\Dto\EventDto;
use AV\JsonProvider\Tests\Support\Dto\EventStatus;
use AV\JsonProvider\Tests\Support\Dto\LabelDto;
use AV\JsonProvider\Tests\Support\Dto\UnboundDto;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

/**
 * Tests for DTO mapping: the object surface of JsonTable, hydration/extraction,
 * temporal (DateTimeImmutable) and backed-enum bridging, the naming convention
 * and #[JsonProviderColumn] override, and the compile-time DTO/schema checks.
 *
 * Runs under a fixed +3 zone so the UTC-on-disk vs local-DTO split is visible.
 */
final class DtoMappingTest
{
    private const string TZ = 'Europe/Moscow';

    private static bool $booted = false;

    #[Test]
    public function insertStoresUtcAndEnumValueOnDisk(): void
    {
        $db = self::db();

        $id = $db->table('dto_events')->insert(self::newEvent());
        Assert::int($id)->greaterThan(0);

        $raw = self::rawLine('dto_events', $id);
        Assert::notNull($raw);
        Assert::same($raw['happens_at'], '2026-07-05 09:30:00');
        Assert::same($raw['status'], 'active');
        Assert::same($raw['on_date'], '2026-07-05');
        Assert::null($raw['priority']);
        Assert::null($raw['ends_at']);

        $db->table('dto_events')->deleteById($id);
    }

    #[Test]
    public function selectOneHydratesDtoWithRoundTrip(): void
    {
        $db = self::db();

        $id = $db->table('dto_events')->insert(self::newEvent(
            priority: EventStatus::Done,
            endsAt: '2026-07-05 14:00:00',
        ));

        $dto = $db->table('dto_events')->where('id', '=', $id)->selectOne();
        Assert::notNull($dto);
        \assert($dto instanceof EventDto);

        Assert::same($dto->id, $id);
        Assert::same($dto->title, 'Event');
        Assert::true($dto->status === EventStatus::Active);
        Assert::true($dto->priority === EventStatus::Done);
        Assert::same(
            $dto->happensAt->format('Y-m-d H:i:s'),
            '2026-07-05 12:30:00',
        );
        Assert::notNull($dto->endsAt);
        Assert::same(
            $dto->endsAt->format('Y-m-d H:i:s'),
            '2026-07-05 14:00:00'
        );
        Assert::same($dto->onDate->format('Y-m-d'), '2026-07-05');
        Assert::same($dto->year, 2026);

        $db->table('dto_events')->deleteById($id);
    }

    #[Test]
    public function selectAllYieldsDtos(): void
    {
        $db = self::db();

        $a = $db->table('dto_events')->insert(self::newEvent(title: 'A'));
        $b = $db->table('dto_events')->insert(self::newEvent(title: 'B'));

        $items = iterator_to_array($db->table('dto_events')
            ->where('id', 'IN', [$a, $b])
            ->orderBy('id', 'asc')
            ->selectAll());

        Assert::count($items, 2);
        \assert($items[0] instanceof EventDto);
        \assert($items[1] instanceof EventDto);
        Assert::same($items[0]->title, 'A');
        Assert::same($items[1]->title, 'B');

        $db->table('dto_events')->deleteById($a);
        $db->table('dto_events')->deleteById($b);
    }

    #[Test]
    public function updateByObjectOverwritesRowById(): void
    {
        $db = self::db();

        $id = $db->table('dto_events')->insert(self::newEvent(title: 'Before'));

        $db->table('dto_events')->update(self::newEvent(
            id: $id,
            title: 'After',
            status: EventStatus::Done,
            year: 2030,
        ));

        $dto = $db->table('dto_events')->where('id', '=', $id)->selectOne();
        Assert::notNull($dto);
        \assert($dto instanceof EventDto);
        Assert::same($dto->title, 'After');
        Assert::true($dto->status === EventStatus::Done);
        Assert::same($dto->year, 2030);

        $db->table('dto_events')->deleteById($id);
    }

    #[Test]
    public function columnAttributeRemapsField(): void
    {
        $db = self::db();

        $id = $db->table('dto_labels')->insert(new LabelDto(0, 'hello'));

        $raw = self::rawLine('dto_labels', $id);
        Assert::notNull($raw);
        Assert::same($raw['label'], 'hello');

        $dto = $db->table('dto_labels')->where('id', '=', $id)->selectOne();
        Assert::notNull($dto);
        \assert($dto instanceof LabelDto);
        Assert::same($dto->text, 'hello');

        $db->table('dto_labels')->deleteById($id);
    }

    #[Test]
    public function invalidStoredEnumValueThrowsOnRead(): void
    {
        $db = self::db();

        $id = $db->table('dto_events')->insertByArray([
            'title'      => 'Bogus',
            'status'     => 'not-a-case',
            'priority'   => null,
            'happens_at' => '2026-01-01 00:00:00',
            'ends_at'    => null,
            'on_date'    => '2026-01-01',
            'year'       => 2026,
        ]);

        $threw = false;

        try {
            $db->table('dto_events')->where('id', '=', $id)->selectOne();
        } catch (JsonProviderException $e) {
            $threw = true;
            Assert::same($e->getErrorKey(), 'InvalidEnumValue');
        }

        Assert::true($threw);

        $db->table('dto_events')->deleteById($id);
    }

    #[Test]
    public function registerRejectsTypeMismatch(): void
    {
        $db = self::db();

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('not compatible');

        $db->registerDto(BadYearDto::class);
    }

    #[Test]
    public function registerRejectsUnboundDto(): void
    {
        $db = self::db();

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('#[JsonProviderRecord]');

        $db->registerDto(UnboundDto::class);
    }

    #[Test]
    public function objectMethodOnUnregisteredTableThrows(): void
    {
        $db = self::db();

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('No DTO is registered');

        $db->table('plain')->selectAll();
    }

    #[Test]
    public function arrayTwinStillWorks(): void
    {
        $db = self::db();

        $id = $db->table('dto_events')->insertByArray([
            'title'      => 'Raw',
            'status'     => 'active',
            'priority'   => null,
            'happens_at' => '2026-05-05 10:00:00',
            'ends_at'    => null,
            'on_date'    => '2026-05-05',
            'year'       => 2026,
        ]);

        $row = $db->table('dto_events')
            ->where('id', '=', $id)
            ->selectOneByArray();
        Assert::notNull($row);
        Assert::same($row['title'], 'Raw');
        Assert::same($row['happens_at'], '2026-05-05 10:00:00');

        $db->table('dto_events')->deleteById($id);
    }

    private static function newEvent(
        int $id = 0,
        string $title = 'Event',
        EventStatus $status = EventStatus::Active,
        EventStatus | null $priority = null,
        string $happensAt = '2026-07-05 12:30:00',
        string | null $endsAt = null,
        string $onDate = '2026-07-05',
        int $year = 2026,
    ): EventDto {
        return new EventDto(
            $id,
            $title,
            $status,
            $priority,
            new \DateTimeImmutable($happensAt),
            $endsAt === null ? null : new \DateTimeImmutable($endsAt),
            new \DateTimeImmutable($onDate),
            $year,
        );
    }

    private static function db(): JsonDataProvider
    {
        date_default_timezone_set(self::TZ);

        if (self::$booted) {
            return JsonDataProvider::getInstance(self::dbPathRoot());
        }

        self::wipe();
        $db = JsonDataProvider::createDatabase(self::dbPathRoot());
        $db->createTable(TableSchema::create(
            name: 'dto_events',
            columns: [
                'id'         => 'int',
                'title'      => 'string',
                'status'     => 'string',
                'priority'   => 'string|null',
                'happens_at' => 'datetime',
                'ends_at'    => 'datetime|null',
                'on_date'    => 'date',
                'year'       => 'year',
            ],
        ));
        $db->createTable(TableSchema::create(
            name: 'dto_labels',
            columns: ['id' => 'int', 'label' => 'string'],
        ));
        $db->createTable(TableSchema::create(
            name: 'plain',
            columns: ['id' => 'int', 'name' => 'string'],
        ));
        $db->registerDto(EventDto::class, LabelDto::class);
        self::$booted = true;

        return $db;
    }

    /**
     * @return null|array<string,null|scalar>
     */
    private static function rawLine(string $table, int $id): array | null
    {
        $path = self::dbPathRoot() . '/' . $table . '/' . $table . '.ndjson';
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

            foreach ($decoded as $key => $value) {
                if (
                    \is_string($key)
                    && (\is_scalar($value) || $value === null)
                ) {
                    $row[$key] = $value;
                }
            }

            return $row;
        }

        return null;
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
        return TempDir::root('jp-dto-tests');
    }
}
