<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\Dto\TimeDto;
use AV\JsonProvider\Tests\Support\TempDir;
use AV\JsonProvider\Validation\TemporalCodec;
use AV\JsonProvider\Validation\TemporalKindEnum;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Storage and query policy for temporal columns, run under a DST zone
 * (Europe/Berlin) so the verbatim-vs-shift split is observable:
 *  - time/timez are stored wall-clock (verbatim), never timezone-shifted
 *    (no DST drift); datetime/datetimez still store UTC;
 *  - sub-second precision: second kinds reject any fraction, millisecond
 *    kinds reject beyond 3 digits — never silently truncating;
 *  - a timezone offset beyond ±14:00 is rejected;
 *  - LIKE on datetime/datetimez is rejected, LIKE on date/time/timez
 *    matches the stored (local) form;
 *  - the DTO object path round-trips a verbatim time unchanged.
 */
final class TemporalPolicyTest
{
    private const string TZ = 'Europe/Berlin';

    private string $dbDir;

    private JsonDataProvider $db;

    private string $savedTz = 'UTC';

    #[BeforeTest]
    public function setUp(): void
    {
        $this->savedTz = date_default_timezone_get();
        date_default_timezone_set(self::TZ);
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
        $this->db->createTable(TableSchema::create(
            name: 't',
            columns: [
                'id'  => 'int',
                'd'   => 'date',
                'tm'  => 'time',
                'tz'  => 'timez',
                'dt'  => 'datetime',
                'dtz' => 'datetimez',
            ],
            indexes: [
                new IndexSchema(
                    name: 'idx_dt',
                    fields: [new IndexFieldSchema(
                        'dt',
                        SortDirectionEnum::ASC,
                    )],
                ),
            ],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
        date_default_timezone_set($this->savedTz);
    }

    #[Test]
    public function timeIsStoredVerbatimRegardlessOfDstSeason(): void
    {
        $id = $this->insert(['tm' => '12:00:00']);

        Assert::same($this->raw($id)['tm'] ?? null, '12:00:00');
        Assert::same($this->read($id)['tm'] ?? null, '12:00:00');
    }

    #[Test]
    public function timezRoundTripsMillisecondsVerbatim(): void
    {
        $id = $this->insert(['tz' => '12:00:00.500']);

        Assert::same($this->raw($id)['tz'] ?? null, '12:00:00.500');
        Assert::same($this->read($id)['tz'] ?? null, '12:00:00.500');
    }

    #[Test]
    public function codecKeepsTimeStableAcrossZones(): void
    {
        $codec = new TemporalCodec();

        date_default_timezone_set('Europe/Berlin');
        $summer = $codec->encode(TemporalKindEnum::Time, '12:00:00');

        date_default_timezone_set('Pacific/Kiritimati');
        $farEast = $codec->encode(TemporalKindEnum::Time, '12:00:00');

        date_default_timezone_set(self::TZ);

        Assert::same($summer, '12:00:00');
        Assert::same(
            $farEast,
            '12:00:00',
            'a verbatim time must not depend on the process timezone',
        );
    }

    #[Test]
    public function datetimeStillShiftsToUtc(): void
    {
        $id = $this->insert(['dt' => '2026-07-05 12:00:00']);

        Assert::same($this->raw($id)['dt'] ?? null, '2026-07-05 10:00:00');
        Assert::same($this->read($id)['dt'] ?? null, '2026-07-05 12:00:00');
    }

    #[Test]
    public function fractionOnSecondKindsIsRejected(): void
    {
        foreach (
            [
                ['dt' => '2026-01-01 10:00:00.5'],
                ['tm' => '10:00:00.5'],
            ] as $override
        ) {
            try {
                $this->insert($override);
                Assert::fail('a fraction on a second kind must be rejected');
            } catch (JsonProviderException $e) {
                Assert::same(
                    $e->getErrorKey(),
                    'TemporalFractionUnsupported',
                    (string)json_encode($override),
                );
            }
        }
    }

    #[Test]
    public function millisecondKindsAcceptUpToThreeDigitsRejectMore(): void
    {
        $ok = $this->insert(['tz' => '10:00:00.500']);
        Assert::same($this->raw($ok)['tz'] ?? null, '10:00:00.500');

        foreach (
            [
                ['tz' => '10:00:00.9999'],
                ['dtz' => '2026-01-01 10:00:00.9999'],
            ] as $override
        ) {
            try {
                $this->insert($override);
                Assert::fail('more than millisecond precision is rejected');
            } catch (JsonProviderException $e) {
                Assert::same(
                    $e->getErrorKey(),
                    'TemporalFractionUnsupported',
                    (string)json_encode($override),
                );
            }
        }
    }

    #[Test]
    public function offsetBeyondFourteenHoursIsRejected(): void
    {
        foreach (['+25:00', '+99:99', '-15:00'] as $offset) {
            try {
                $this->insert(['dt' => '2026-01-01 10:00:00' . $offset]);
                Assert::fail('an out-of-range offset must be rejected');
            } catch (JsonProviderException $e) {
                Assert::same(
                    $e->getErrorKey(),
                    'InvalidTemporalValue',
                    $offset,
                );
            }
        }
    }

    #[Test]
    public function offsetsWithinRangeAreAccepted(): void
    {
        $accepted = 0;

        foreach (
            ['+14:00', '-12:00', '+05:45', '+12:45', 'Z', '+00:00'] as $offset
        ) {
            $this->insert(['dt' => '2026-01-01 10:00:00' . $offset]);
            $accepted++;
        }

        Assert::same($accepted, 6);
    }

    #[Test]
    public function likeOnDatetimeIsRejectedOnEveryPath(): void
    {
        $this->insert(['dt' => '2026-07-05 12:00:00']);

        try {
            $this->db->table('t')
                ->where('dt', 'LIKE', '2026%')->selectAllByArray();
            Assert::fail('LIKE on datetime must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'LikeOnInstantUnsupported');
        }

        try {
            $this->db->table('t')
                ->where('dtz', 'LIKE', '2026%')->selectAllByArray();
            Assert::fail('LIKE on datetimez must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'LikeOnInstantUnsupported');
        }
    }

    #[Test]
    public function dtoTimePropertyRoundTripsVerbatim(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'dto_time',
            columns: ['id' => 'int', 'at_time' => 'time'],
        ));
        $this->db->registerDto(TimeDto::class);

        $id = $this->db->table('dto_time')->insert(
            new TimeDto(0, new \DateTimeImmutable('1970-01-01 14:30:45')),
        );

        $path = $this->dbDir . '/dto_time/dto_time.ndjson';
        Assert::string((string)file_get_contents($path))
            ->contains('"at_time":"14:30:45"');

        $dto = $this->db->table('dto_time')
            ->where('id', '=', $id)->selectOne();
        \assert($dto instanceof TimeDto);
        Assert::same($dto->atTime->format('H:i:s'), '14:30:45');
    }

    #[Test]
    public function verbatimTimeParsingUsesFixedDstImmuneAnchor(): void
    {
        $codec = new TemporalCodec();

        // '02:30:00' is inside Europe/Berlin's spring-forward gap on a
        // transition day; a "today in the local zone" anchor rolls it forward
        // to 03:30:00. A fixed 1970-01-01 UTC anchor keeps it verbatim, so the
        // DTO read path no longer drifts under DST regardless of the date.
        foreach ([TemporalKindEnum::Time, TemporalKindEnum::TimeZ] as $kind) {
            $value = $kind === TemporalKindEnum::TimeZ
                ? '02:30:00.000'
                : '02:30:00';
            $dt = $codec->localStringToDateTime($kind, $value);

            Assert::same($dt->format('H:i:s'), '02:30:00');
            Assert::same($dt->format('Y-m-d'), '1970-01-01');
            Assert::same($dt->getTimezone()->getName(), 'UTC');
            Assert::same(
                $codec->dateTimeToLocalString($kind, $dt),
                $value,
                'a verbatim time must round-trip unchanged',
            );
        }
    }

    #[Test]
    public function likeOnDateTimeTimezMatchesLocalStoredForm(): void
    {
        $id = $this->insert([
            'd'  => '2026-07-05',
            'tm' => '12:34:56',
            'tz' => '12:34:56.250',
        ]);

        $byDate = $this->db->table('t')
            ->where('d', 'LIKE', '2026-07%')->selectAllByArray();
        Assert::count($byDate, 1);
        Assert::same($byDate[0]['id'], $id);

        $byTime = $this->db->table('t')
            ->where('tm', 'LIKE', '12:34:%')->selectAllByArray();
        Assert::count($byTime, 1);

        $byTimez = $this->db->table('t')
            ->where('tz', 'LIKE', '12:34:56.%')->selectAllByArray();
        Assert::count($byTimez, 1);
    }

    /**
     * @param array<string,null|scalar> $overrides
     */
    private function insert(array $overrides): int
    {
        return $this->db->table('t')->insertByArray(array_merge([
            'd'   => '2026-01-01',
            'tm'  => '00:00:00',
            'tz'  => '00:00:00.000',
            'dt'  => '2026-01-01 00:00:00',
            'dtz' => '2026-01-01 00:00:00.000',
        ], $overrides));
    }

    /**
     * @return array<string,null|scalar>
     */
    private function read(int $id): array
    {
        $row = $this->db->table('t')
            ->where('id', '=', $id)->selectOneByArray();
        \assert(\is_array($row));

        return $row;
    }

    /**
     * @return array<string,null|scalar>
     */
    private function raw(int $id): array
    {
        $path = $this->dbDir . '/t/t.ndjson';
        $contents = (string)file_get_contents($path);

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

        return [];
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
        return TempDir::root('jp-temporal-policy-tests');
    }
}
