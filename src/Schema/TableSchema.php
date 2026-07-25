<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

use AV\JsonProvider\Exception\JsonProviderSchemaException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

/**
 * Single-table schema descriptor.
 *
 * Primary key contract (see PrimaryKey):
 *  - `columns` must contain field `id` of type `int`,
 *  - `id` is always at position 0,
 *  - autoincrement is provided by MetaRegistry,
 *  - `indexes` always has the PK index (name 'pk', isPrimary=true) at
 *    position 0.
 *
 * Physical file naming for the table data file is the provider's contract,
 * not user input: `<table-name>.ndjson`, placed in the table's subdirectory.
 *
 * The constructor **validates** the PK contract and throws
 * the primary key contract cases on any violation. This prevents silent
 * acceptance of a corrupted schema (e.g. tampered information_schema.json).
 *
 * For convenient assembly from a "human" description (without `id`, without
 * the PK index) use the TableSchema::create() factory — it normalizes columns
 * and indexes before validation.
 */
final class TableSchema
{
    /**
     * @param string                      $name              logical table
     *                                                       name (no extension)
     * @param array<int,UniqueConstraint> $uniqueConstraints unique constraints
     *                                                       (PK is not included
     *                                                       here)
     * @param array<string,string>        $columns           name => type,
     *                                                       one of the closed
     *                                                       ColumnTypes list
     * @param array<int,IndexSchema>      $indexes           indexes; PK index
     *                                                       must be present at
     *                                                       position 0
     * @param null|string                 $tableComment      human description
     *                                                       of the table (null
     *                                                       = none)
     * @param array<string,string>        $columnComment     column name =>
     *                                                       human description
     *                                                       (only documented
     *                                                       ones)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $uniqueConstraints = [],
        public readonly array $columns = [],
        public readonly array $indexes = [],
        public readonly string | null $tableComment = null,
        public readonly array $columnComment = [],
    ) {
        IdentifierRules::assertTableName($name);

        foreach (array_keys($columns) as $column) {
            IdentifierRules::assertColumnName($column);
        }

        self::validatePrimaryKey($name, $columns);
        self::validateColumnTypes($name, $columns);
        self::validateIndexes($name, $indexes);
    }

    /**
     * Factory with PK normalization.
     *
     * Behaviour:
     *  - if `id` is missing in `columns` — prepends `id => 'int'`;
     *  - if `id` is present but not at position 0 — moves it to the front;
     *  - if `id` is present with a non-`int` type — passes as-is, validation in
     *    the constructor throws (PK type is not normalized: it is semantic);
     *  - if `indexes` has no PK index (name 'pk' with isPrimary=true) —
     *    prepends one via IndexSchema::primaryFor().
     *
     * @param array<int,UniqueConstraint> $uniqueConstraints
     * @param array<string,string>        $columns
     * @param array<int,IndexSchema>      $indexes
     * @param null|string                 $tableComment      human description
     *                                                       of the table
     * @param array<string,string>        $columnComment     column name =>
     *                                                       human description
     */
    public static function create(
        string $name,
        array $uniqueConstraints = [],
        array $columns = [],
        array $indexes = [],
        string | null $tableComment = null,
        array $columnComment = [],
    ): self {
        $normalizedColumns = self::normalizeColumns($columns);

        return new self(
            name: $name,
            uniqueConstraints: $uniqueConstraints,
            columns: $normalizedColumns,
            indexes: self::normalizeIndexes($indexes),
            tableComment: $tableComment,
            columnComment: self::normalizeColumnComment(
                $normalizedColumns,
                $columnComment,
            ),
        );
    }

    /**
     * Returns the comment for a single column, or null if the column has none
     * (or does not exist).
     */
    public function getColumnComment(string $column): string | null
    {
        return $this->columnComment[$column] ?? null;
    }

    /**
     * Returns a copy of the schema with the table comment replaced. Passing
     * null removes the comment. Structure is untouched — the PK contract still
     * holds.
     */
    public function withTableComment(string | null $comment): self
    {
        return new self(
            name: $this->name,
            uniqueConstraints: $this->uniqueConstraints,
            columns: $this->columns,
            indexes: $this->indexes,
            tableComment: $comment,
            columnComment: $this->columnComment,
        );
    }

    /**
     * Returns a copy of the schema with a single column comment set (null
     * unsets it). Comments for columns absent from `columns` are dropped
     * silently — this method does not validate column existence (the provider
     * API does).
     */
    public function withColumnComment(
        string $column,
        string | null $comment,
    ): self {
        $comments = $this->columnComment;

        if ($comment === null || $comment === '') {
            unset($comments[$column]);
        } else {
            $comments[$column] = $comment;
        }

        return $this->withColumnComments($comments);
    }

    /**
     * Returns a copy of the schema with the whole column-comment map replaced.
     * Entries for unknown columns and empty strings are dropped.
     *
     * @param array<string,string> $comments
     */
    public function withColumnComments(array $comments): self
    {
        return new self(
            name: $this->name,
            uniqueConstraints: $this->uniqueConstraints,
            columns: $this->columns,
            indexes: $this->indexes,
            tableComment: $this->tableComment,
            columnComment: self::normalizeColumnComment(
                $this->columns,
                $comments,
            ),
        );
    }

    /**
     * Returns the physical NDJSON file name for this table inside its
     * subdirectory. Format: `<table-name>.ndjson`. The subdirectory is added by
     * NdjsonStorage.
     */
    public function getFileName(): string
    {
        return $this->name . '.ndjson';
    }

    /**
     * Normalizes columns: `id` is always first; if missing, it is prepended.
     * The type of `id` is left as-is: a wrong type triggers a validation error.
     *
     * @param array<string,string> $columns
     *
     * @return array<string,string>
     */
    private static function normalizeColumns(array $columns): array
    {
        $idType = $columns[PrimaryKey::FIELD] ?? PrimaryKey::TYPE;
        unset($columns[PrimaryKey::FIELD]);

        return [PrimaryKey::FIELD => $idType] + $columns;
    }

    /**
     * Normalizes indexes: PK index is always first. If missing, prepends it.
     * If an index named 'pk' is present without isPrimary=true — the
     * constructor validation throws (the name is reserved).
     *
     * @param array<int,IndexSchema> $indexes
     *
     * @return array<int,IndexSchema>
     */
    private static function normalizeIndexes(array $indexes): array
    {
        foreach ($indexes as $index) {
            if ($index->isPrimary) {
                return $indexes;
            }
        }

        return array_merge([IndexSchema::primaryFor()], $indexes);
    }

    /**
     * Keeps only comments that describe an existing column and are non-empty
     * strings. Order follows the columns order, so persisted comments read in
     * the same order as the columns they annotate.
     *
     * @param array<string,string> $columns
     * @param array<string,string> $comments
     *
     * @return array<string,string>
     */
    private static function normalizeColumnComment(
        array $columns,
        array $comments,
    ): array {
        $normalized = [];

        foreach (array_keys($columns) as $column) {
            $text = $comments[$column] ?? null;

            if (\is_string($text) && $text !== '') {
                $normalized[$column] = $text;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string,string> $columns
     */
    private static function validatePrimaryKey(
        string $tableName,
        array $columns,
    ): void {
        if (!isset($columns[PrimaryKey::FIELD])) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::PkColumnMissing,
                $tableName,
                PrimaryKey::FIELD,
            );
        }

        if ($columns[PrimaryKey::FIELD] !== PrimaryKey::TYPE) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::PkColumnType,
                $tableName,
                PrimaryKey::FIELD,
                PrimaryKey::TYPE,
                $columns[PrimaryKey::FIELD],
            );
        }

        $firstField = array_key_first($columns);

        if ($firstField !== PrimaryKey::FIELD) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::PkColumnNotFirst,
                $tableName,
                PrimaryKey::FIELD,
                $firstField,
            );
        }
    }

    /**
     * Every declared column type must be one of the closed set in
     * ColumnTypes (a base type, optionally with the "|null" suffix). The
     * check runs at the schema boundary, so it covers the DDL API and
     * loading information_schema.json alike: a typo like "integer" or
     * "datetime|nullable" fails loudly instead of silently degrading the
     * column to unvalidated passthrough.
     *
     * @param array<string,string> $columns
     */
    private static function validateColumnTypes(
        string $tableName,
        array $columns,
    ): void {
        foreach ($columns as $column => $type) {
            if (!ColumnTypes::isValid($type)) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::InvalidColumnType,
                    $tableName,
                    $column,
                    $type,
                );
            }
        }
    }

    /**
     * Validates the indexes list:
     *  - the name 'pk' is reserved: an index with that name must be the
     *    PK (isPrimary=true);
     *  - the PK index must be present;
     *  - the PK index must be at position 0 (so it gets priority in
     *    resolveIndex).
     *
     * @param array<int,IndexSchema> $indexes
     */
    private static function validateIndexes(
        string $tableName,
        array $indexes,
    ): void {
        $pkSeenAt = null;

        foreach ($indexes as $position => $index) {
            if ($index->name === IndexSchema::PK_NAME && !$index->isPrimary) {
                throw new JsonProviderSchemaException(
                    JsonProviderErrorEn::PkIndexNameReserved,
                    $tableName,
                    IndexSchema::PK_NAME,
                );
            }

            if ($index->isPrimary) {
                if ($pkSeenAt !== null) {
                    throw new JsonProviderSchemaException(
                        JsonProviderErrorEn::PkIndexDuplicate,
                        $tableName,
                    );
                }
                $pkSeenAt = $position;
            }
        }

        if ($pkSeenAt === null) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::PkIndexMissing,
                $tableName,
            );
        }

        if ($pkSeenAt !== 0) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::PkIndexPosition,
                $tableName,
                (string)$pkSeenAt,
            );
        }
    }
}
