<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Query\SortDirectionEnum;

/**
 * Describes a table index.
 * The index file stores {key, line} pairs sorted lexicographically by key,
 * enabling fast ordering and range lookups without reading the full table.
 *
 * Physical file naming is the provider's contract, not user input:
 * `<index-name>.index.ndjson`, placed in the table's subdirectory.
 *
 * The name 'pk' is reserved for the primary key index: it is auto-created
 * by TableSchema::create() and marked with isPrimary=true.
 */
final class IndexSchema
{
    public const string PK_NAME = 'pk';

    /**
     * @param string                      $name      unique index name
     * @param array<int,IndexFieldSchema> $fields    index fields in priority
     *                                               order
     * @param bool                        $isPrimary true only for the PK index
     *                                               (id ASC)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
        public readonly bool $isPrimary = false,
    ) {
        IdentifierRules::assertIndexName($name);
    }

    /**
     * Builds the PK index descriptor.
     * Single field id ASC, isPrimary=true.
     */
    public static function primaryFor(): self
    {
        return new self(
            name: self::PK_NAME,
            fields: [
                new IndexFieldSchema(PrimaryKey::FIELD, SortDirectionEnum::ASC),
            ],
            isPrimary: true,
        );
    }

    /**
     * Returns the physical file name for this index inside the table
     * subdirectory. Format: `<index-name>.index.ndjson`. The table subdirectory
     * is added by NdjsonStorage.
     */
    public function getFileName(): string
    {
        return $this->name . '.index.ndjson';
    }

    /**
     * Checks whether this index covers the given ordering exactly.
     * Match is strict: field count, names, and directions must all be equal.
     *
     * @param array<int,OrderBy> $ordering
     */
    public function matchesOrdering(array $ordering): bool
    {
        if (\count($ordering) !== \count($this->fields)) {
            return false;
        }

        foreach ($ordering as $i => $order) {
            $indexField = $this->fields[$i] ?? null;

            if ($indexField === null) {
                return false;
            }

            if (
                $order->field !== $indexField->field
                || $order->direction !== $indexField->direction
            ) {
                return false;
            }
        }

        return true;
    }
}
