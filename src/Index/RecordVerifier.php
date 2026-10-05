<?php

declare(strict_types=1);

namespace AV\JsonProvider\Index;

use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Schema\IndexSchema;

/**
 * Checks each record read for a line of a lookup against the index entry
 * that pointed at that line: the key built from the record must be the
 * entry's key, or INDEX_UNRELIABLE is raised. A key is built once per
 * distinct set of values (IndexKey::valuesTag) — a wide run repeats few
 * values many times.
 */
final class RecordVerifier
{
    /** @var array<string,string> keys by IndexKey::valuesTag() */
    private array $built = [];

    /** @var array<int,string> keys of a single int field, by value */
    private array $builtInt = [];

    /** @var array<array-key,string> keys of a single string field, by value */
    private array $builtString = [];

    /** @var array<int,string> */
    private readonly array $keys;

    /** the field of a single-field index, null for a composite one */
    private readonly string | null $field;

    /**
     * Built after the lookup: it takes the keys of the entries the lookup
     * returned.
     */
    public function __construct(
        private readonly string $tableName,
        private readonly IndexSchema $index,
        SortedIndexEntries $head,
        SortedIndexEntries $tail,
    ) {
        $this->keys = $head->keys() + $tail->keys();
        $this->field = \count($index->fields) === 1
            ? $index->fields[0]->field
            : null;
    }

    /**
     * @param array<string,null|scalar> $record
     */
    public function check(int $line, array $record): void
    {
        $key = $this->keys[$line] ?? null;
        $value = $this->field === null ? null : ($record[$this->field] ?? null);
        $expected = match (true) {
            $this->field === null => $this->built[
                IndexKey::valuesTag($record, $this->index)
            ] ??= IndexKey::build($record, $this->index),
            \is_int($value) => $this->builtInt[$value]
                ??= IndexKey::build($record, $this->index),
            \is_string($value) => $this->builtString[$value]
                ??= IndexKey::build($record, $this->index),
            default => $this->built[self::tag($value)]
                ??= IndexKey::build($record, $this->index),
        };

        if ($key === null || $expected !== $key) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::IndexRecordMismatch,
                $this->index->name,
                $this->tableName,
            );
        }
    }

    /**
     * IndexKey::valuesTag() of a single-field index, for one value.
     */
    private static function tag(
        bool | float | int | string | null $value,
    ): string {
        return match (true) {
            \is_int($value)    => 'i' . $value . ';',
            \is_string($value) => 's' . \strlen($value) . ':' . $value,
            $value === null    => 'n',
            \is_float($value)  => 'f' . pack('e', $value),
            default            => $value ? 't' : 'u',
        };
    }
}
