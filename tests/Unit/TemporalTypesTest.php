<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

/**
 * Tests for the temporal column types (date/time/timez/datetime/datetimez) and
 * the numeric parts (year/month/day).
 *
 * The suite runs under a fixed non-UTC, non-DST zone (Europe/Moscow, +3) so the
 * UTC storage vs local presentation split is observable and deterministic:
 *  - datetime/datetimez are stored in UTC on disk and presented back in the
 *    local zone (exact round-trip);
 *  - date, time and timez are stored verbatim (wall-clock, no shift);
 *  - datetime/datetimez filter values are encoded to UTC too, so a query
 *    stated in local time still matches — including through an index.
 */
final class TemporalTypesTest
{
    private const string TZ = 'Europe/Moscow';
    private const string TABLE = 'events';

    private static bool $booted = false;

    #[Test]
    public function datetimeIsStoredInUtcAndPresentedLocal(): void
    {
        $db = self::db();

        $id = $db->table(self::TABLE)->insertByArray([
            'title'      => 'Launch',
            'happens_at' => '2026-07-05 12:30:00',
            'on_date'    => '2026-07-05',
            'at_time'    => '23:30:00',
            'precise'    => '2026-07-05 12:30:00.250',
            'yr'         => 2026,
            'mo'         => 7,
            'dy'         => 5,
        ]);

        $raw = self::rawLine($id);
        Assert::notNull($raw);
        Assert::same($raw['happens_at'], '2026-07-05 09:30:00');
        Assert::same($raw['precise'], '2026-07-05 09:30:00.250');
        Assert::same($raw['at_time'], '23:30:00');

        $row = $db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::same($row['happens_at'], '2026-07-05 12:30:00');
        Assert::same($row['precise'], '2026-07-05 12:30:00.250');
        Assert::same($row['at_time'], '23:30:00');

        $db->table(self::TABLE)->deleteById($id);
    }

    #[Test]
    public function dateIsStoredVerbatimWithoutTimezoneShift(): void
    {
        $db = self::db();

        $id = self::insertEvent($db, ['on_date' => '2026-07-05']);

        $raw = self::rawLine($id);
        Assert::notNull($raw);
        Assert::same($raw['on_date'], '2026-07-05');

        $row = $db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::same($row['on_date'], '2026-07-05');

        $db->table(self::TABLE)->deleteById($id);
    }

    #[Test]
    public function timeIsStoredVerbatimWithoutTimezoneShift(): void
    {
        $db = self::db();

        $id = self::insertEvent($db, ['at_time' => '01:15:00']);

        $raw = self::rawLine($id);
        Assert::notNull($raw);
        Assert::same($raw['at_time'], '01:15:00');

        $row = $db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::same($row['at_time'], '01:15:00');

        $db->table(self::TABLE)->deleteById($id);
    }

    #[Test]
    public function acceptsIsoSeparatorZuluAndOffsetForms(): void
    {
        $db = self::db();

        $tsep = self::insertEvent($db, ['happens_at' => '2026-07-05T12:30:00']);
        Assert::same(
            self::rawField($tsep, 'happens_at'),
            '2026-07-05 09:30:00',
        );

        $zulu = self::insertEvent(
            $db,
            ['happens_at' => '2026-07-05T12:30:00Z'],
        );
        Assert::same(
            self::rawField($zulu, 'happens_at'),
            '2026-07-05 12:30:00',
        );

        $off = self::insertEvent(
            $db,
            ['happens_at' => '2026-07-05T12:30:00+05:00'],
        );
        Assert::same(
            self::rawField($off, 'happens_at'),
            '2026-07-05 07:30:00',
        );

        $db->table(self::TABLE)->deleteById($tsep);
        $db->table(self::TABLE)->deleteById($zulu);
        $db->table(self::TABLE)->deleteById($off);
    }

    #[Test]
    public function rejectsNonSystemDateFormats(): void
    {
        $db = self::db();
        $bad = ['05.07.2026', '2026/07/05', '05-07-2026', '2026-13-01'];
        $rejected = 0;

        foreach ($bad as $value) {
            try {
                $db->table(self::TABLE)->insertByArray(
                    self::event(['on_date' => $value]),
                );
            } catch (JsonProviderException) {
                $rejected++;
            }
        }

        Assert::same($rejected, \count($bad));
    }

    #[Test]
    public function rejectsZeroDate(): void
    {
        $db = self::db();

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('zero dates');

        $db->table(self::TABLE)->insertByArray(
            self::event(['on_date' => '0000-00-00'])
        );
    }

    #[Test]
    public function rejectsImpossibleDate(): void
    {
        $db = self::db();

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('invalid datetime');

        $db->table(self::TABLE)->insertByArray(
            self::event(['happens_at' => '2026-02-30 10:00:00']),
        );
    }

    #[Test]
    public function yearAcceptsNegativeForBeforeCommonEra(): void
    {
        $db = self::db();

        $id = self::insertEvent($db, ['yr' => -44]);

        $raw = self::rawLine($id);
        Assert::notNull($raw);
        Assert::same($raw['yr'], -44);

        $db->table(self::TABLE)->deleteById($id);
    }

    #[Test]
    public function monthOutOfRangeIsRejected(): void
    {
        $db = self::db();

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('out of range');

        $db->table(self::TABLE)->insertByArray(self::event(['mo' => 13]));
    }

    #[Test]
    public function dayOutOfRangeIsRejected(): void
    {
        $db = self::db();

        Expect::exception(JsonProviderException::class)
            ->withMessageContaining('out of range');

        $db->table(self::TABLE)->insertByArray(self::event(['dy' => 32]));
    }

    #[Test]
    public function queryByLocalDatetimeMatchesStoredUtc(): void
    {
        $db = self::db();

        $id = self::insertEvent($db, ['happens_at' => '2026-08-01 15:00:00']);

        $hit = $db->table(self::TABLE)
            ->where('happens_at', '=', '2026-08-01 15:00:00')
            ->selectOneByArray();
        Assert::notNull($hit);
        Assert::same($hit['id'], $id);

        $hitZ = $db->table(self::TABLE)
            ->where('happens_at', '=', '2026-08-01T12:00:00Z')
            ->selectOneByArray();
        Assert::notNull($hitZ);
        Assert::same($hitZ['id'], $id);

        $db->table(self::TABLE)->deleteById($id);
    }

    #[Test]
    public function betweenUsesLocalBoundsAndOrderByIsChronological(): void
    {
        $db = self::db();

        $a = self::insertEvent($db, ['happens_at' => '2026-06-01 10:00:00']);
        $b = self::insertEvent($db, ['happens_at' => '2026-06-15 10:00:00']);
        $c = self::insertEvent($db, ['happens_at' => '2026-07-01 10:00:00']);

        $june = $db->table(self::TABLE)
            ->where('id', 'IN', [$a, $b, $c])
            ->where('happens_at', 'BETWEEN', [
                '2026-06-01 00:00:00',
                '2026-06-30 23:59:59',
            ])
            ->orderBy('happens_at', 'asc')
            ->selectAllByArray();

        Assert::count($june, 2);
        Assert::same($june[0]['id'], $a);
        Assert::same($june[1]['id'], $b);
        Assert::same($june[0]['happens_at'], '2026-06-01 10:00:00');

        $db->table(self::TABLE)->deleteById($a);
        $db->table(self::TABLE)->deleteById($b);
        $db->table(self::TABLE)->deleteById($c);
    }

    #[Test]
    public function nullableTemporalStoresAndReadsNull(): void
    {
        $db = self::db();

        $id = self::insertEvent($db, ['note' => null]);

        $row = $db->table(self::TABLE)
            ->where('id', '=', $id)->selectOneByArray();
        Assert::notNull($row);
        Assert::null($row['ended_at']);
        Assert::null($row['note']);

        $db->table(self::TABLE)->deleteById($id);
    }

    #[Test]
    public function instantOutsideYearsZeroToNineThousandInUtcIsRejected(): void
    {
        $db = self::db();

        foreach (
            [
                '9999-12-31 23:00:00-05:00',
                '9999-12-31T20:00:00-05:00',
                '0000-01-01 01:00:00+03:00',
            ] as $value
        ) {
            try {
                self::insertEvent($db, ['happens_at' => $value]);
                Assert::fail('must be rejected: ' . $value);
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'InvalidTemporalValue', $value);
            }
        }

        $id = self::insertEvent(
            $db,
            ['happens_at' => '9999-12-31 18:00:00-05:00'],
        );
        Assert::same(self::rawField($id, 'happens_at'), '9999-12-31 23:00:00');
        $db->table(self::TABLE)->deleteById($id);
    }

    #[Test]
    public function temporalValueWithTrailingNewlineIsRejected(): void
    {
        $db = self::db();

        foreach (
            [
                'at_time'    => "10:00:00\n",
                'happens_at' => "2026-01-01 00:00:00\n",
                'on_date'    => "2026-01-01\n",
            ] as $column => $value
        ) {
            try {
                self::insertEvent($db, [$column => $value]);
                Assert::fail('must be rejected: ' . $column);
            } catch (JsonProviderException $e) {
                Assert::same(
                    $e->getErrorKey(),
                    'InvalidTemporalValue',
                    $column,
                );
            }
        }
    }

    /**
     * A complete, all-valid event with the given overrides applied on top.
     *
     * @param array<string,null|scalar> $overrides
     *
     * @return array<string,null|scalar>
     */
    private static function event(array $overrides = []): array
    {
        return array_merge([
            'title'      => 'E',
            'happens_at' => '2026-01-01 00:00:00',
            'on_date'    => '2026-01-01',
            'at_time'    => '00:00:00',
            'precise'    => '2026-01-01 00:00:00.000',
            'yr'         => 2026,
            'mo'         => 1,
            'dy'         => 1,
        ], $overrides);
    }

    /**
     * @param array<string,null|scalar> $overrides
     */
    private static function insertEvent(
        JsonDataProvider $db,
        array $overrides = [],
    ): int {
        return $db->table(self::TABLE)->insertByArray(self::event($overrides));
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
            name: self::TABLE,
            columns: [
                'id'         => 'int',
                'title'      => 'string',
                'happens_at' => 'datetime',
                'ended_at'   => 'datetime|null',
                'on_date'    => 'date',
                'at_time'    => 'time',
                'precise'    => 'datetimez',
                'yr'         => 'year',
                'mo'         => 'month',
                'dy'         => 'day',
                'note'       => 'string|null',
            ],
            indexes: [
                new IndexSchema(
                    name: 'idx_happens',
                    fields: [new IndexFieldSchema(
                        'happens_at',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));
        self::$booted = true;

        return $db;
    }

    /**
     * Returns a single field from the raw (on-disk, UTC) record for the id.
     * Asserts the record exists so callers read a definite value.
     */
    private static function rawField(
        int $id,
        string $field,
    ): bool | float | int | string | null {
        $raw = self::rawLine($id);
        \assert($raw !== null);

        return $raw[$field] ?? null;
    }

    /**
     * Reads the raw (on-disk, UTC) record for the given id, bypassing decode.
     *
     * @return null|array<string,null|scalar>
     */
    private static function rawLine(int $id): array | null
    {
        $path = self::dbPathRoot() . '/' . self::TABLE . '/' . self::TABLE
            . '.ndjson';
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
        return TempDir::root('jp-temporal-tests');
    }
}
