<?php

declare(strict_types=1);

namespace AV\JsonProvider\Exception;

/**
 * English locale — the default, always present.
 * name  = error key (must match case names in all other locales).
 * value = translated string; positional %s placeholders.
 */
enum LangEnEnum: string implements LocaleInterface
{
    case FILE_NOT_READABLE = 'Storage file is not readable: %s';
    case FILE_NOT_WRITABLE = 'Storage file is not writable: %s';
    case LOCK_FAILED = 'Failed to acquire file lock: %s';
    case INVALID_JSON = 'Invalid data format in storage file: %s';
    case TABLE_NOT_FOUND = 'Table not found in schema: %s';
    case TABLE_ALREADY_EXISTS = 'Table "%s" already exists';
    case COLUMN_NOT_FOUND = 'Table "%s" has no column named "%s"';
    case TABLE_FILE_EXISTS = 'Table file already exists: %s';
    case DATABASE_ALREADY_EXISTS = 'Database already exists at path: %s';
    case INVALID_FILE_NAME = 'Invalid file name: %s';
    case INVALID_INDEX_FILE_NAME = 'Invalid index file name: %s';
    case UNIQUE_VIOLATION = 'Unique constraint violation in table "%s" '
        . 'on fields [%s]: %s';
    case INVALID_RECORD = 'Invalid record in table "%s": %s';
    case SCHEMA_NOT_FOUND = 'Schema file not found: %s';
    case INVALID_SCHEMA = 'Storage schema error: %s';
    case SCHEMA_SERIALIZE_FAILED = 'Failed to serialize schema';
    case EXTENSION_REQUIRED = 'PHP extension required to use this cache '
        . 'adapter: %s';
    case PK_CONTRACT_VIOLATED = 'Primary key contract violated for '
        . 'table "%s": %s';
    case META_ENTRY_MISSING = 'No meta entry for table "%s" — table is not '
        . 'registered with the provider';
    case REORDER_COLUMNS_UNKNOWN = 'reorderColumns for table "%s": '
        . 'unknown column "%s"';
    case REORDER_COLUMNS_DUPLICATE = 'reorderColumns for table "%s": '
        . 'duplicate column "%s"';
    case REORDER_COLUMNS_INCOMPLETE = 'reorderColumns for table "%s": '
        . 'missing column(s) "%s"';
    case INDEX_NOT_FOUND = 'Table "%s" has no index named "%s"';
    case BACKUP_ARCHIVE_EXISTS = 'Backup archive already exists at: %s';
    case BACKUP_DESTINATION_INSIDE_DB = 'Backup destination must be outside '
        . 'the DB directory: %s';
    case BACKUP_ARCHIVE_CORRUPT = 'Backup archive is invalid: %s';
    case BACKUP_SCHEMA_MISMATCH = 'Backup schema does not match current DB '
        . 'schema: %s';
    case RESTORE_FAILED = 'Restore failed: %s';
    case TYPE_MISMATCH = 'Table "%s", column "%s": expected type %s, '
        . 'got %s';
    case NULL_NOT_ALLOWED = 'Table "%s", column "%s" is not nullable but '
        . 'null was given';
    case REQUIRED_COLUMN_MISSING = 'Table "%s": required column "%s" '
        . 'is missing';
    case INVALID_TEMPORAL_VALUE = 'Table "%s", column "%s": invalid %s '
        . 'value: "%s"';
    case ZERO_DATE = 'Table "%s", column "%s": zero dates are not allowed: '
        . '"%s"';
    case NUMERIC_PART_OUT_OF_RANGE = 'Table "%s", column "%s": %s value out '
        . 'of range: %s';
    case DTO_SCHEMA_MISMATCH = 'DTO %s does not match table "%s": %s';
    case DTO_NOT_REGISTERED = 'No DTO is registered for table "%s"; use the '
        . '*ByArray methods or registerDto()';
    case INVALID_ENUM_VALUE = 'Table "%s", column "%s": stored value "%s" is '
        . 'not a valid case of %s';
    case DTO_HYDRATION_FAILED = 'Table "%s", column "%s": cannot hydrate DTO '
        . '— %s';

    public static function translate(string $key, string ...$params): string
    {
        foreach (self::cases() as $case) {
            if ($case->name === $key) {
                return $params !== []
                    ? \sprintf($case->value, ...$params)
                    : $case->value;
            }
        }

        return $key;
    }
}
