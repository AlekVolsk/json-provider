# Exceptions and localization

All provider exceptions descend from `AV\JsonProvider\Exception\JsonProviderException`. The concrete class for storage-related errors is `StorageException`.

```php
use AV\JsonProvider\Exception\StorageException;

try {
    $db->table('users')->insertByArray(['email' => $existing]);
} catch (StorageException $e) {
    $e->getMessage();           // English (for logs)
    $e->getLocalizedMessage();  // current locale (for users)
    $e->getErrorKey();          // e.g. 'UNIQUE_VIOLATION' — for programmatic handling
}
```

## Setting a locale

```php
use AV\JsonProvider\Exception\LangRuEnum;

$db->setLocale(LangRuEnum);  // any case — only the class matters
```

The locale applies process-wide. `getMessage()` always stays English (for stable log lines). `getLocalizedMessage()` returns the translated form; unknown keys fall back to English.

## Custom locale

```php
use AV\JsonProvider\Exception\LocaleInterface;

enum LangDeEnum: string implements LocaleInterface
{
    case TABLE_NOT_FOUND  = 'Tabelle "%s" nicht im Schema gefunden';
    case UNIQUE_VIOLATION = 'Eindeutigkeitsverletzung in "%s" auf Feldern [%s]: %s';
    // missing keys fall back to English

    public static function translate(string $key, string ...$params): string
    {
        foreach (self::cases() as $case) {
            if ($case->name === $key) {
                return $params !== [] ? \sprintf($case->value, ...$params) : $case->value;
            }
        }
        return $key;   // fall back to English
    }
}

$db->setLocale(LangDeEnum::TABLE_NOT_FOUND);
```

The custom locale class can live in any namespace.

## Common error keys

| Key | When |
| - | - |
| `TABLE_NOT_FOUND` | unknown table name |
| `TABLE_ALREADY_EXISTS` | `createTable` for a name already registered |
| `COLUMN_NOT_FOUND` | comment API called for a non-existent column |
| `DATABASE_ALREADY_EXISTS` | `createDatabase` for an existing path |
| `UNIQUE_VIOLATION` | unique constraint violated on insert/update |
| `FOREIGN_KEY_RESTRICT` | `restrict` action blocked a delete/update |
| `PK_CONTRACT_VIOLATED` | PK or PK index contract was broken |
| `META_ENTRY_MISSING` | meta entry missing for a registered table |
| `META_ENTRY_CORRUPT` | the meta entry is broken (a non-int counter) — `repair()` fixes it |
| `INDEX_NOT_FOUND` | named index does not exist in the table schema |
| `REORDER_COLUMNS_UNKNOWN` | reorderColumns: column not in schema |
| `REORDER_COLUMNS_DUPLICATE` | reorderColumns: duplicate column name |
| `REORDER_COLUMNS_INCOMPLETE` | reorderColumns: some columns omitted |
| `MIGRATE_COLUMN_TYPE_CHANGE` | migrateColumns: a retained column changes type |
| `MIGRATE_COLUMN_NO_DEFAULT` | migrateColumns: not-null column with no default added to a non-empty table |
| `MIGRATE_FIELD_UNKNOWN_COLUMN` | a constraint/index references a missing column (migrateColumns, addIndex, addUniqueConstraint) |
| `INVALID_TABLE_NAME` | table name outside the identifier whitelist (or the reserved `_pendingRename`) |
| `INVALID_COLUMN_NAME` | column name outside the identifier whitelist |
| `INVALID_INDEX_NAME` | index name outside the identifier whitelist |
| `RESERVED_INDEX_NAME` | index name starts with the reserved `_fk_` prefix |
| `INVALID_COLUMN_TYPE` | column type outside the closed set of 24 types |
| `RELATION_ENTRY_INVALID` | broken relation entry in information_schema.json (keys/type) |
| `RELATION_ACTION_INVALID` | onDelete/onUpdate outside noAction/cascade/setNull/restrict |
| `RELATION_COLUMN_NOT_FOUND` | the FK/referenced column does not exist in the canonical relation table |
| `RELATION_TYPE_MISMATCH` | the base types of the FK and referenced columns differ |
| `RELATION_ALREADY_EXISTS` | addRelation: the canonical edge is already declared (in either notation) |
| `RELATION_NOT_FOUND` | dropRelation: no relation by the (from, foreignKey, to) triple |
| `RELATION_REFERENCES_NOT_UNIQUE` | references is neither the PK nor covered by a single-column unique |
| `RELATION_ON_UPDATE_ON_PK` | onUpdate declared on the immutable PK `id` |
| `FOREIGN_KEY_SET_NULL_NOT_NULLABLE` | setNull on a non-nullable FK column (declaration or execution) |
| `FK_BACKING_INDEX_MISSING` | a restrict probe without a covering backing index — run `repair()` |
| `INDEX_ALREADY_EXISTS` | addIndex: the index name is taken |
| `UNIQUE_CONSTRAINT_ALREADY_EXISTS` | addUniqueConstraint: the constraint name is taken |
| `UNIQUE_CONSTRAINT_NOT_FOUND` | dropUniqueConstraint: no constraint by that name |
| `COLUMN_ALREADY_EXISTS` | renameColumn: the target column name is taken |
| `RENAME_INCOMPLETE` | a write/DDL into a table with a live `_pendingRename` marker — run `repair()` first |
| `BACKUP_ARCHIVE_EXISTS` | backup destination already occupied |
| `BACKUP_DESTINATION_INSIDE_DB` | backup target lies inside the DB directory |
| `BACKUP_ARCHIVE_CORRUPT` | restore: archive missing/unreadable/wrong format |
| `BACKUP_SCHEMA_MISMATCH` | restore: archive table set does not match schema |
| `BACKUP_CHECKSUM_MISMATCH` | restore: an archive member failed the manifest's sha256 check |
| `RESTORE_FAILED` | restore failed (rolled back if possible) |
| `EXTENSION_REQUIRED` | a cache adapter cannot find its PHP extension |
| `TYPE_MISMATCH` | a value does not match the column's declared type |
| `NULL_NOT_ALLOWED` | `null` given for a non-nullable column |
| `REQUIRED_COLUMN_MISSING` | insert omitted a non-nullable column |
| `NON_FINITE_FLOAT` | `NAN`/`INF` written into a float column — not representable in JSON |
| `INVALID_UTF8` | string value is not valid UTF-8 |
| `QUERY_UNKNOWN_COLUMN` | a where/orderBy/distinct/selectColumn column is missing from the schema |
| `CONDITION_TYPE_MISMATCH` | a where condition value does not fit the column type/operator |
| `CONDITION_MALFORMED` | malformed BETWEEN/IN shape (not an array, not [min, max]) |
| `INVALID_SORT_DIRECTION` | orderBy direction is not "asc"/"desc" |
| `INVALID_LIMIT` | negative limit |
| `INVALID_OFFSET` | negative offset |
| `INVALID_TEMPORAL_VALUE` | date/time value not in the accepted system format |
| `ZERO_DATE` | a zero date (`0000-00-00`) was supplied |
| `NUMERIC_PART_OUT_OF_RANGE` | `month`/`day` value outside its valid range |
| `DTO_SCHEMA_MISMATCH` | a registered DTO does not match its table schema |
| `DTO_NOT_REGISTERED` | an object method used on a table with no DTO bound |
| `INVALID_ENUM_VALUE` | a stored value is not a case of the DTO's enum |
| `DTO_HYDRATION_FAILED` | a stored value cannot be hydrated into the DTO |
