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
