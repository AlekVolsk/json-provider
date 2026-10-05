<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Storage\NdjsonStorage;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Data lines turned into records by every reader of NdjsonStorage: a flat
 * object is taken as decoded, any other line keeps only its fields with a
 * string key and a scalar or null value — exactly what checking every
 * field of every line yields.
 */
final class RecordDecodeTest
{
    private const string TABLE = 'rows';

    private const string FILE = 'rows.ndjson';

    private const array LINES = [
        '{"id":1,"a":"plain","b":2,"c":1.5,"d":true,"e":null}',
        '{"id":2,"a":"has [ and { inside","b":"x,\"1\":y"}',
        '{"id":3,"nested":{"x":1},"a":"kept"}',
        '{"id":4,"list":[1,2],"a":"kept"}',
        '{"id":5,"1":"int key","a":"kept"}',
        '{"id":6,"-7":"negative int key","a":"kept"}',
        '{"id":7,"\u0031\u0032":"escaped int key","a":"kept"}',
        '{"id":8,"01":"not an int key","1.5":"nor this","a":"kept"}',
        '[1,2,3]',
        '{}',
        '{"1":"only an int key"}',
        ' {"id":12,"a":"leading space"}',
        '{"id":13,"a":"\u00e9\u4e2d","b":"\\\","c":"\","}',
        '{"id":14,"a":"x"} ',
        'not json',
        '{"id":16,"emptyobj":{},"emptylist":[]}',
    ];

    private string $root;

    private NdjsonStorage $storage;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-record-decode');
        mkdir($this->root . '/' . self::TABLE, 0755, true);
        file_put_contents(
            $this->root . '/' . self::TABLE . '/' . self::FILE,
            implode("\n", self::LINES) . "\n",
        );
        $this->storage = new NdjsonStorage($this->root);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * Every reader yields the record each line gives when every field is
     * checked: whole reads, reads by line number, by byte offset, one line
     * and the last line.
     */
    #[Test]
    public function readersMatchFieldByFieldCheck(): void
    {
        $expected = array_map(self::reference(...), self::LINES);
        $kept = array_values(array_filter(
            $expected,
            static fn (array | null $r): bool => $r !== null,
        ));

        Assert::same($this->storage->read(self::TABLE, self::FILE), $kept);
        Assert::same(
            $this->storage->readRawLines(self::TABLE, self::FILE)['records'],
            $kept,
        );

        $numbers = array_keys(self::LINES);
        Assert::same(
            $this->storage->readLines(self::TABLE, self::FILE, $numbers),
            $kept,
        );

        $offsets = [];
        $position = 0;

        foreach (self::LINES as $number => $line) {
            $offsets[$number] = $position;
            $position += \strlen($line) + 1;
        }

        Assert::same(
            $this->storage->readLinesAt(self::TABLE, self::FILE, $offsets),
            $kept,
        );

        foreach (self::LINES as $number => $line) {
            Assert::same(
                $this->storage->readLine(self::TABLE, self::FILE, $number),
                $expected[$number],
                $line,
            );
        }

        Assert::same(
            $this->storage->readLastLine(self::TABLE, self::FILE),
            $expected[array_key_last(self::LINES)],
        );
    }

    /**
     * The streaming reader yields every record keyed by its line number,
     * or only the requested lines.
     */
    #[Test]
    public function recordsStreamByLineNumber(): void
    {
        $expected = array_filter(
            array_map(self::reference(...), self::LINES),
            static fn (array | null $r): bool => $r !== null,
        );

        Assert::same(
            iterator_to_array($this->storage->records(self::TABLE, self::FILE)),
            $expected,
        );

        $wanted = [0, 2, 4, 9, 13];
        Assert::same(
            iterator_to_array(
                $this->storage->records(self::TABLE, self::FILE, $wanted),
            ),
            array_intersect_key($expected, array_flip($wanted)),
        );
    }

    /**
     * Lines that decode to no usable record are reported as broken.
     */
    #[Test]
    public function unusableLinesAreBroken(): void
    {
        $broken = array_column(
            $this->storage->readRawLines(self::TABLE, self::FILE)['broken'],
            'line',
        );
        $expected = array_keys(array_filter(
            array_map(self::reference(...), self::LINES),
            static fn (array | null $r): bool => $r === null,
        ));

        Assert::same($broken, $expected);
    }

    /**
     * The record a line gives when every field of the decoded line is
     * checked: fields with a string key and a scalar or null value.
     *
     * @return null|array<string,null|scalar>
     */
    private static function reference(string $line): array | null
    {
        $item = json_decode(trim($line), true);

        if (!\is_array($item)) {
            return null;
        }

        $row = [];

        foreach ($item as $key => $value) {
            if (\is_string($key) && (\is_scalar($value) || $value === null)) {
                $row[$key] = $value;
            }
        }

        return $row === [] ? null : $row;
    }
}
