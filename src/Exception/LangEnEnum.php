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
    case LOCK_TIMEOUT = 'Failed to acquire %s lock on %s within %s seconds';
    case LOCK_ORDER_VIOLATION = 'Lock ordering violated: %s (locks must be '
        . 'acquired database-first, then tables in name order, never '
        . 'upgraded)';
    case INVALID_JSON = 'Invalid data format in storage file: %s';
    case TABLE_NOT_FOUND = 'Table not found in schema: %s';
    case TABLE_ALREADY_EXISTS = 'Table "%s" already exists';
    case COLUMN_NOT_FOUND = 'Table "%s" has no column named "%s"';
    case TABLE_FILE_EXISTS = 'Table file already exists: %s';
    case DATABASE_ALREADY_EXISTS = 'Database already exists at path: %s';
    case INVALID_FILE_NAME = 'Invalid file name: %s';
    case INVALID_INDEX_FILE_NAME = 'Invalid index file name: %s';
    case INVALID_TABLE_NAME = 'Invalid table name "%s": must start with a '
        . 'letter, digit or underscore and contain only letters, digits, '
        . 'underscores or hyphens (max 64 characters, no dots or path '
        . 'separators)';
    case INVALID_COLUMN_NAME = 'Invalid column name "%s": must start with a '
        . 'letter, digit or underscore and contain only letters, digits, '
        . 'underscores or hyphens (max 64 characters, no dots or path '
        . 'separators)';
    case INVALID_INDEX_NAME = 'Invalid index name "%s": must start with a '
        . 'letter, digit or underscore and contain only letters, digits, '
        . 'underscores or hyphens (max 64 characters, no dots or path '
        . 'separators)';
    case RESERVED_INDEX_NAME = 'Index name "%s" is reserved: the "_fk_" '
        . 'prefix is set aside for engine-managed FK backing indexes';
    case INVALID_COLUMN_TYPE = 'Table "%s", column "%s": unknown column type '
        . '"%s"; allowed types are string, int, float, bool, date, time, '
        . 'timez, datetime, datetimez, year, month, day, each optionally '
        . 'with the "|null" suffix';
    case RELATION_ENTRY_INVALID = 'Invalid relation entry in '
        . 'information_schema.json: %s';
    case RELATION_ACTION_INVALID = 'Invalid relation %s action "%s": allowed '
        . 'values are noAction, cascade, setNull, restrict';
    case INDEX_ALREADY_EXISTS = 'Table "%s" already has an index named "%s"';
    case UNIQUE_CONSTRAINT_ALREADY_EXISTS = 'Table "%s" already has a unique '
        . 'constraint named "%s"';
    case UNIQUE_CONSTRAINT_NOT_FOUND = 'Table "%s" has no unique constraint '
        . 'named "%s"';
    case COLUMN_ALREADY_EXISTS = 'Table "%s" already has a column named "%s"';
    case FOREIGN_KEY_RESTRICT = 'Operation blocked by RESTRICT: table "%s" '
        . 'has rows whose "%s" references the affected rows of table "%s"';
    case RENAME_INCOMPLETE = 'A previous renameTable "%s" -> "%s" did not '
        . 'complete; run repair() on the database before touching the '
        . 'affected tables';
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
    case INDEX_UNRELIABLE = 'Index "%s" on table "%s" is structurally '
        . 'corrupt and cannot be trusted: %s';
    case INDEX_KEY_NON_FINITE = 'Index key for field "%s": NAN and INF '
        . 'are not indexable';
    case QUERY_UNKNOWN_COLUMN = 'Table "%s" has no column "%s" '
        . '(referenced in %s)';
    case CONDITION_TYPE_MISMATCH = 'Table "%s", column "%s", operator %s: '
        . 'condition value must be %s, got %s';
    case CONDITION_MALFORMED = 'Table "%s", column "%s", operator %s: %s';
    case INVALID_SORT_DIRECTION = 'Table "%s": invalid sort direction '
        . '"%s" (expected "asc" or "desc", case-insensitive)';
    case INVALID_OPERATOR = 'Table "%s": unknown filter operator "%s"';
    case LIKE_EVALUATION_FAILED = 'LIKE pattern "%s" could not be '
        . 'evaluated: %s';
    case INVALID_LIMIT = 'Table "%s": limit must be a non-negative '
        . 'integer, got %s';
    case INVALID_OFFSET = 'Table "%s": offset must be a non-negative '
        . 'integer, got %s';
    case MIGRATE_COLUMN_TYPE_CHANGE = 'migrateColumns for table "%s": column '
        . '"%s" type change (%s -> %s) is not supported; migrate the data '
        . 'separately';
    case MIGRATE_COLUMN_NO_DEFAULT = 'migrateColumns for table "%s": cannot '
        . 'add non-nullable column "%s" of type %s to a non-empty table (no '
        . 'default value); declare it nullable';
    case MIGRATE_FIELD_UNKNOWN_COLUMN = 'migrateColumns for table "%s": %s '
        . 'references unknown column "%s"';
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
    case NON_FINITE_FLOAT = 'Table "%s", column "%s": NAN and INF are not '
        . 'storable float values';
    case INVALID_UTF8 = 'Table "%s", column "%s": string value is not '
        . 'valid UTF-8';
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
