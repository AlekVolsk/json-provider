<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

use AV\JsonProvider\Exception\JsonProviderSchemaException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
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
 *
 * Service indexes (isService=true) are engine-managed FK backing indexes:
 * they carry the reserved "_fk_" name prefix, are maintained by the
 * regular index write machinery, but are invisible to query planning
 * (resolveIndex skips them) and cannot be created or dropped through the
 * public index DDL — relation DDL owns their lifecycle.
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
     * @param bool                        $isService true for engine-managed
     *                                               FK backing indexes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
        public readonly bool $isPrimary = false,
        public readonly bool $isService = false,
    ) {
        if ($isService) {
            IdentifierRules::assertServiceIndexName($name);
        } else {
            IdentifierRules::assertIndexName($name);
        }

        if ($fields === []) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::IndexFieldsEmpty,
                $name,
            );
        }
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
        return self::fileNameFor($this->name);
    }

    /**
     * The index file name for an index given by name only.
     */
    public static function fileNameFor(string $indexName): string
    {
        return IdentifierRules::physicalName($indexName) . '.index.ndjson';
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
