<?php

declare(strict_types=1);

namespace AV\JsonProvider\Exception;

/**
 * Storage-layer exceptions for the provider.
 */
class StorageException extends JsonProviderException
{
    public static function fileNotReadable(string $path): self
    {
        return new self('FILE_NOT_READABLE', $path);
    }

    public static function fileNotWritable(string $path): self
    {
        return new self('FILE_NOT_WRITABLE', $path);
    }

    public static function lockFailed(string $path): self
    {
        return new self('LOCK_FAILED', $path);
    }

    public static function lockTimeout(
        string $mode,
        string $subject,
        string $seconds,
    ): self {
        return new self('LOCK_TIMEOUT', $mode, $subject, $seconds);
    }

    public static function lockOrderViolation(string $details): self
    {
        return new self('LOCK_ORDER_VIOLATION', $details);
    }

    public static function invalidJson(string $path): self
    {
        return new self('INVALID_JSON', $path);
    }

    public static function tableNotFound(string $table): self
    {
        return new self('TABLE_NOT_FOUND', $table);
    }

    public static function tableAlreadyExists(string $table): self
    {
        return new self('TABLE_ALREADY_EXISTS', $table);
    }

    public static function columnNotFound(string $table, string $column): self
    {
        return new self('COLUMN_NOT_FOUND', $table, $column);
    }

    public static function tableFileExists(string $path): self
    {
        return new self('TABLE_FILE_EXISTS', $path);
    }

    public static function databaseAlreadyExists(string $path): self
    {
        return new self('DATABASE_ALREADY_EXISTS', $path);
    }

    public static function invalidFileName(string $fileName): self
    {
        return new self('INVALID_FILE_NAME', $fileName);
    }

    public static function invalidTableName(string $name): self
    {
        return new self('INVALID_TABLE_NAME', $name);
    }

    public static function invalidColumnName(string $name): self
    {
        return new self('INVALID_COLUMN_NAME', $name);
    }

    public static function invalidIndexName(string $name): self
    {
        return new self('INVALID_INDEX_NAME', $name);
    }

    public static function reservedIndexName(string $name): self
    {
        return new self('RESERVED_INDEX_NAME', $name);
    }

    public static function invalidColumnType(
        string $table,
        string $column,
        string $type,
    ): self {
        return new self('INVALID_COLUMN_TYPE', $table, $column, $type);
    }

    public static function relationEntryInvalid(string $reason): self
    {
        return new self('RELATION_ENTRY_INVALID', $reason);
    }

    public static function relationActionInvalid(
        string $field,
        string $value,
    ): self {
        return new self('RELATION_ACTION_INVALID', $field, $value);
    }

    public static function indexAlreadyExists(
        string $table,
        string $indexName,
    ): self {
        return new self('INDEX_ALREADY_EXISTS', $table, $indexName);
    }

    public static function uniqueConstraintAlreadyExists(
        string $table,
        string $name,
    ): self {
        return new self('UNIQUE_CONSTRAINT_ALREADY_EXISTS', $table, $name);
    }

    public static function uniqueConstraintNotFound(
        string $table,
        string $name,
    ): self {
        return new self('UNIQUE_CONSTRAINT_NOT_FOUND', $table, $name);
    }

    public static function columnAlreadyExists(
        string $table,
        string $column,
    ): self {
        return new self('COLUMN_ALREADY_EXISTS', $table, $column);
    }

    public static function renameIncomplete(string $from, string $to): self
    {
        return new self('RENAME_INCOMPLETE', $from, $to);
    }

    public static function invalidIndexFileName(string $fileName): self
    {
        return new self('INVALID_INDEX_FILE_NAME', $fileName);
    }

    public static function uniqueViolation(
        string $table,
        string $fields,
        string $value,
    ): self {
        return new self('UNIQUE_VIOLATION', $table, $fields, $value);
    }

    public static function invalidRecord(string $table, string $reason): self
    {
        return new self('INVALID_RECORD', $table, $reason);
    }

    public static function schemaNotFound(string $path): self
    {
        return new self('SCHEMA_NOT_FOUND', $path);
    }

    public static function invalidSchema(string $reason): self
    {
        return new self('INVALID_SCHEMA', $reason);
    }

    public static function schemaSerializeFailed(): self
    {
        return new self('SCHEMA_SERIALIZE_FAILED');
    }

    public static function extensionRequired(string $extensionName): self
    {
        return new self('EXTENSION_REQUIRED', $extensionName);
    }

    public static function foreignKeyRestrict(
        string $childTable,
        string $foreignKey,
        string $parentTable,
    ): self {
        return new self(
            'FOREIGN_KEY_RESTRICT',
            $childTable,
            $foreignKey,
            $parentTable,
        );
    }

    public static function pkContractViolated(
        string $table,
        string $reason,
    ): self {
        return new self('PK_CONTRACT_VIOLATED', $table, $reason);
    }

    public static function metaEntryMissing(string $table): self
    {
        return new self('META_ENTRY_MISSING', $table);
    }

    public static function reorderColumnsUnknown(
        string $table,
        string $field,
    ): self {
        return new self('REORDER_COLUMNS_UNKNOWN', $table, $field);
    }

    public static function reorderColumnsDuplicate(
        string $table,
        string $field,
    ): self {
        return new self('REORDER_COLUMNS_DUPLICATE', $table, $field);
    }

    public static function reorderColumnsIncomplete(
        string $table,
        string $missing,
    ): self {
        return new self('REORDER_COLUMNS_INCOMPLETE', $table, $missing);
    }

    public static function indexNotFound(string $table, string $indexName): self
    {
        return new self('INDEX_NOT_FOUND', $table, $indexName);
    }

    public static function migrateColumnTypeChange(
        string $table,
        string $column,
        string $from,
        string $to,
    ): self {
        return new self(
            'MIGRATE_COLUMN_TYPE_CHANGE',
            $table,
            $column,
            $from,
            $to,
        );
    }

    public static function migrateColumnNoDefault(
        string $table,
        string $column,
        string $type,
    ): self {
        return new self(
            'MIGRATE_COLUMN_NO_DEFAULT',
            $table,
            $column,
            $type,
        );
    }

    public static function migrateFieldUnknownColumn(
        string $table,
        string $owner,
        string $field,
    ): self {
        return new self(
            'MIGRATE_FIELD_UNKNOWN_COLUMN',
            $table,
            $owner,
            $field,
        );
    }

    public static function backupArchiveExists(string $path): self
    {
        return new self('BACKUP_ARCHIVE_EXISTS', $path);
    }

    public static function backupDestinationInsideDb(string $path): self
    {
        return new self('BACKUP_DESTINATION_INSIDE_DB', $path);
    }

    public static function backupArchiveCorrupt(string $reason): self
    {
        return new self('BACKUP_ARCHIVE_CORRUPT', $reason);
    }

    public static function backupSchemaMismatch(string $details): self
    {
        return new self('BACKUP_SCHEMA_MISMATCH', $details);
    }

    public static function restoreFailed(string $details): self
    {
        return new self('RESTORE_FAILED', $details);
    }

    public static function typeMismatch(
        string $table,
        string $column,
        string $expected,
        string $actual,
    ): self {
        return new self('TYPE_MISMATCH', $table, $column, $expected, $actual);
    }

    public static function nullNotAllowed(string $table, string $column): self
    {
        return new self('NULL_NOT_ALLOWED', $table, $column);
    }

    public static function requiredColumnMissing(
        string $table,
        string $column,
    ): self {
        return new self('REQUIRED_COLUMN_MISSING', $table, $column);
    }

    public static function nonFiniteFloat(string $table, string $column): self
    {
        return new self('NON_FINITE_FLOAT', $table, $column);
    }

    public static function indexKeyNonFinite(string $field): self
    {
        return new self('INDEX_KEY_NON_FINITE', $field);
    }

    public static function queryUnknownColumn(
        string $table,
        string $column,
        string $context,
    ): self {
        return new self('QUERY_UNKNOWN_COLUMN', $table, $column, $context);
    }

    public static function conditionTypeMismatch(
        string $table,
        string $column,
        string $operator,
        string $expected,
        string $actual,
    ): self {
        return new self(
            'CONDITION_TYPE_MISMATCH',
            $table,
            $column,
            $operator,
            $expected,
            $actual,
        );
    }

    public static function conditionMalformed(
        string $table,
        string $column,
        string $operator,
        string $reason,
    ): self {
        return new self(
            'CONDITION_MALFORMED',
            $table,
            $column,
            $operator,
            $reason,
        );
    }

    public static function invalidSortDirection(
        string $table,
        string $direction,
    ): self {
        return new self('INVALID_SORT_DIRECTION', $table, $direction);
    }

    public static function invalidOperator(
        string $table,
        string $operator,
    ): self {
        return new self('INVALID_OPERATOR', $table, $operator);
    }

    public static function likeEvaluationFailed(
        string $pattern,
        string $reason,
    ): self {
        return new self('LIKE_EVALUATION_FAILED', $pattern, $reason);
    }

    public static function invalidLimit(string $table, int $value): self
    {
        return new self('INVALID_LIMIT', $table, (string)$value);
    }

    public static function invalidOffset(string $table, int $value): self
    {
        return new self('INVALID_OFFSET', $table, (string)$value);
    }

    public static function indexUnreliable(
        string $table,
        string $indexName,
        string $reason,
    ): self {
        return new self('INDEX_UNRELIABLE', $indexName, $table, $reason);
    }

    public static function invalidUtf8(string $table, string $column): self
    {
        return new self('INVALID_UTF8', $table, $column);
    }

    public static function invalidTemporalValue(
        string $table,
        string $column,
        string $type,
        string $value,
    ): self {
        return new self(
            'INVALID_TEMPORAL_VALUE',
            $table,
            $column,
            $type,
            $value,
        );
    }

    public static function zeroDate(
        string $table,
        string $column,
        string $value,
    ): self {
        return new self('ZERO_DATE', $table, $column, $value);
    }

    public static function numericPartOutOfRange(
        string $table,
        string $column,
        string $type,
        string $value,
    ): self {
        return new self(
            'NUMERIC_PART_OUT_OF_RANGE',
            $table,
            $column,
            $type,
            $value,
        );
    }

    public static function dtoSchemaMismatch(
        string $class,
        string $table,
        string $reason,
    ): self {
        return new self('DTO_SCHEMA_MISMATCH', $class, $table, $reason);
    }

    public static function dtoNotRegistered(string $table): self
    {
        return new self('DTO_NOT_REGISTERED', $table);
    }

    public static function invalidEnumValue(
        string $table,
        string $column,
        string $value,
        string $enumClass,
    ): self {
        return new self(
            'INVALID_ENUM_VALUE',
            $table,
            $column,
            $value,
            $enumClass,
        );
    }

    public static function dtoHydrationFailed(
        string $table,
        string $column,
        string $reason,
    ): self {
        return new self('DTO_HYDRATION_FAILED', $table, $column, $reason);
    }
}
