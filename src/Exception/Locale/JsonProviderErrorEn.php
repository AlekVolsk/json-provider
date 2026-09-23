<?php

declare(strict_types=1);

namespace AV\JsonProvider\Exception\Locale;

/**
 * English error vocabulary — the canonical one: a throw site names a case of
 * this enum, so the case name is the locale-independent identifier of the
 * situation ($e->code, getErrorKey()) and the value is the message rendered
 * when no other locale knows the case.
 *
 * name  = situation identifier (the same case names in every locale).
 * value = message template; placeholders are positional %s in argument order.
 */
enum JsonProviderErrorEn: string implements LocaleInterface
{
    case FileNotReadable = 'Storage file is not readable: %s';
    case FileNotWritable = 'Storage file is not writable: %s';
    case InvalidJson = 'Invalid data format in storage file: %s';
    case InvalidFileName = 'Invalid file name: %s';

    case LockFailed = 'Failed to acquire the file lock: %s';
    case LockTimeoutSharedDatabase = 'Failed to acquire the shared lock on '
        . 'the database within %s seconds';
    case LockTimeoutExclusiveDatabase = 'Failed to acquire the exclusive '
        . 'lock on the database within %s seconds';
    case LockTimeoutSharedTable = 'Failed to acquire the shared lock on '
        . 'table "%s" within %s seconds';
    case LockTimeoutExclusiveTable = 'Failed to acquire the exclusive lock '
        . 'on table "%s" within %s seconds';
    case LockTimeoutExclusiveServiceFile = 'Failed to acquire the exclusive '
        . 'lock on service file "%s" within %s seconds';
    case LockDepthDesync = 'Internal error: the nesting depth of database '
        . 'locks went out of sync while releasing them';
    case WriteLockRequired = 'Internal error: operation "%s" requires the '
        . 'exclusive lock on table "%s"';
    case LockOrderAfterServiceFile = 'Lock ordering violated: table and '
        . 'database locks cannot be acquired while a service-file lock is '
        . 'held';
    case LockOrderDatabaseUpgrade = 'Lock ordering violated: the database '
        . 'lock cannot be upgraded from shared to exclusive';
    case LockOrderDatabaseAfterTables = 'Lock ordering violated: the '
        . 'database lock was requested while table locks are held — the '
        . 'database is locked first';
    case LockOrderTableUpgrade = 'Lock ordering violated: the lock on table '
        . '"%s" cannot be upgraded from shared to exclusive';
    case LockOrderTableOutsideHeldSet = 'Lock ordering violated: table "%s" '
        . 'is outside the held lock set';
    case LockOrderServiceFileNested = 'Lock ordering violated: the '
        . 'service-file lock "%s" was requested while "%s" is held';
    case LockOrderDeleteRequiresExclusive = 'Deleting the lock file of table '
        . '"%s" requires the exclusive lock on that table';
    case LockNestedTransaction = 'Nested transaction on table "%s" is not '
        . 'supported';
    case LockPlanStale = 'Could not settle the lock plan for a write to '
        . 'table "%s": the relations of the table keep changing concurrently '
        . 'with the request';

    case TableNotFound = 'Table "%s" not found in schema';
    case TableAlreadyExists = 'Table "%s" already exists';
    case TableFileExists = 'Table file already exists: %s';
    case DatabaseAlreadyExists = 'Database already exists at path: %s';
    case RenameIncomplete = 'A previous rename of table "%s" -> "%s" did not '
        . 'complete; run a database repair before touching the affected '
        . 'tables';

    case ColumnNotFound = 'Table "%s" has no column named "%s"';
    case ColumnAlreadyExists = 'Table "%s" already has a column named "%s"';
    case IndexNotFound = 'Table "%s" has no index named "%s"';
    case IndexAlreadyExists = 'Table "%s" already has an index named "%s"';
    case UniqueConstraintNotFound = 'Table "%s" has no unique constraint '
        . 'named "%s"';
    case UniqueConstraintAlreadyExists = 'Table "%s" already has a unique '
        . 'constraint named "%s"';
    case IndexFieldsEmpty = 'Index "%s" must cover at least one column';
    case UniqueConstraintFieldsEmpty = 'Unique constraint "%s" must cover at '
        . 'least one column';
    case IndexUnknownColumn = 'Table "%s": index "%s" references unknown '
        . 'column "%s"';
    case UniqueConstraintUnknownColumn = 'Table "%s": unique constraint "%s" '
        . 'references unknown column "%s"';
    case InvalidTableName = 'Invalid table name "%s": must start with a '
        . 'letter, digit or underscore and contain only letters, digits, '
        . 'underscores or hyphens (max 64 characters, no dots or path '
        . 'separators)';
    case InvalidColumnName = 'Invalid column name "%s": must start with a '
        . 'letter, digit or underscore and contain only letters, digits, '
        . 'underscores or hyphens (max 64 characters, no dots or path '
        . 'separators)';
    case InvalidIndexName = 'Invalid index name "%s": must start with a '
        . 'letter, digit or underscore and contain only letters, digits, '
        . 'underscores or hyphens (max 64 characters, no dots or path '
        . 'separators)';
    case ReservedIndexName = 'Index name "%s" is reserved: the "_fk_" prefix '
        . 'is set aside for the foreign key indexes the engine maintains '
        . 'itself';
    case InvalidColumnType = 'Table "%s", column "%s": unknown column type '
        . '"%s"; allowed types are string, int, float, bool, date, time, '
        . 'timez, datetime, datetimez, year, month, day, each optionally '
        . 'with the "|null" suffix';
    case ReorderColumnsUnknown = 'Column reordering for table "%s": unknown '
        . 'column "%s"';
    case ReorderColumnsDuplicate = 'Column reordering for table "%s": '
        . 'duplicate column "%s"';
    case ReorderColumnsIncomplete = 'Column reordering for table "%s": '
        . 'missing column(s) "%s"';
    case MigrateColumnTypeChange = 'Column migration for table "%s": '
        . 'changing the type of column "%s" (%s -> %s) is not supported; '
        . 'migrate the data separately';
    case MigrateColumnNoDefault = 'Column migration for table "%s": cannot '
        . 'add non-nullable column "%s" of type %s to a non-empty table, as '
        . 'there is no safe default; declare it nullable';
    case SchemaTransformNoResult = 'Internal error: rebuilding the schema of '
        . 'table "%s" produced no result';
    case MigrateFieldUnknownColumnRelation = 'Column migration for table '
        . '"%s": relation %s(%s) -> %s references unknown column "%s" — drop '
        . 'the relation first';
    case PkColumnMissing = 'Primary key contract violated for table "%s": '
        . 'the mandatory primary key column "%s" is missing';
    case PkColumnType = 'Primary key contract violated for table "%s": '
        . 'column "%s" must have type "%s", actual type: "%s"';
    case PkColumnNotFirst = 'Primary key contract violated for table "%s": '
        . 'column "%s" must come first in the column list, actual first '
        . 'column: "%s"';
    case PkIndexNameReserved = 'Primary key contract violated for table '
        . '"%s": index name "%s" is reserved for the primary key index';
    case PkIndexDuplicate = 'Primary key contract violated for table "%s": '
        . 'there must be exactly one primary key index';
    case PkIndexMissing = 'Primary key contract violated for table "%s": the '
        . 'mandatory primary key index is missing';
    case PkIndexPosition = 'Primary key contract violated for table "%s": '
        . 'the primary key index must come first in the index list, actual '
        . 'position: %s';
    case PkColumnNotRenamable = 'Primary key contract violated for table '
        . '"%s": the primary key column cannot be renamed';
    case PkIndexNotDroppable = 'Primary key contract violated for table '
        . '"%s": the primary key index cannot be dropped';
    case PkIndexNotAddable = 'Primary key contract violated for table "%s": '
        . 'the primary key index cannot be added or replaced';
    case SchemaTablesMissing = 'Storage schema error: the "tables" key is '
        . 'missing';
    case SchemaTablesNotCollection = 'Storage schema error: the "tables" key '
        . 'is not a collection';
    case SchemaRelationsNotList = 'Storage schema error: the "relations" key '
        . 'is not a list';
    case SchemaTableNamesClash = 'Storage schema error: tables "%s" and '
        . '"%s" differ only in letter case and would share one directory';
    case SchemaTableNotObject = 'Storage schema error, table "%s": the '
        . 'definition is not an object';
    case SchemaColumnsNotObject = 'Storage schema error, table "%s": '
        . '"columns" is not an object';
    case SchemaColumnTypeNotString = 'Storage schema error, table "%s", '
        . 'column "%s": the type is not a string';
    case SchemaUniqueNotList = 'Storage schema error, table "%s": "unique" '
        . 'is not a list';
    case SchemaUniqueNotObject = 'Storage schema error, table "%s": a unique '
        . 'constraint entry is not an object';
    case SchemaUniqueName = 'Storage schema error, table "%s": a unique '
        . 'constraint has a missing or non-string name';
    case SchemaUniqueFields = 'Storage schema error, table "%s", unique '
        . 'constraint "%s": "fields" is missing, empty or not a list';
    case SchemaUniqueFieldNotString = 'Storage schema error, table "%s", '
        . 'unique constraint "%s": a "fields" entry is not a string';
    case SchemaIndexesNotList = 'Storage schema error, table "%s": "indexes" '
        . 'is not a list';
    case SchemaIndexNotObject = 'Storage schema error, table "%s": an index '
        . 'entry is not an object';
    case SchemaIndexName = 'Storage schema error, table "%s": an index has a '
        . 'missing or non-string name';
    case SchemaIndexFields = 'Storage schema error, table "%s", index "%s": '
        . '"fields" is missing, empty or not a list';
    case SchemaIndexFieldNotObject = 'Storage schema error, table "%s", '
        . 'index "%s": a "fields" entry is not an object';
    case SchemaIndexFieldName = 'Storage schema error, table "%s", index '
        . '"%s": a "fields" entry has a missing or non-string "field"';
    case SchemaIndexDirection = 'Storage schema error, table "%s", index '
        . '"%s", field "%s": invalid direction %s (expected "asc" or '
        . '"desc")';

    case RelationActionInvalid = 'Invalid relation action "%s": "%s"; '
        . 'allowed values are noAction, cascade, setNull, restrict';
    case RelationColumnNotFound = 'Relation %s(%s) -> %s(%s): column "%s" '
        . 'does not exist in table "%s" (belongsTo holds the foreign key '
        . 'column in its from-table; hasMany/hasOne hold it in their '
        . 'to-table)';
    case RelationTypeMismatch = 'Relation type mismatch: the foreign key '
        . 'column "%s"."%s" (%s) must share the base type of the referenced '
        . 'column "%s"."%s" (%s); the "|null" suffix is ignored';
    case RelationAlreadyExists = 'Relation %s(%s) -> %s is already declared '
        . 'as: %s (one relation is declared once, in either notation)';
    case RelationNotFound = 'Relation %s(%s) -> %s is not declared';
    case RelationReferencesNotUnique = 'Relation references a non-unique '
        . 'column: "%s"."%s" must be the primary key or be covered by a '
        . 'single-column unique constraint';
    case RelationOnUpdateOnPk = 'Relation %s -> %s: the "onUpdate" action is '
        . 'declared on the immutable primary key "id" and could never fire — '
        . 'drop the action or reference a non-primary unique column';
    case ForeignKeySetNullNotNullable = 'The "setNull" action is declared on '
        . 'the foreign key column "%s"."%s", which does not accept null — '
        . 'make the column "|null" or change the action';
    case ForeignKeyRestrict = 'Operation blocked by the "restrict" action: '
        . 'table "%s" has rows whose "%s" references the affected rows of '
        . 'table "%s"';
    case FkBackingIndexMissing = 'The service index for the foreign key '
        . '"%s"."%s" is missing or does not cover the column — run a '
        . 'database repair to provision it';
    case RelationEntryNotObject = 'Invalid relation entry #%s in '
        . 'information_schema.json: the entry is not an object; expected '
        . 'keys: from, foreignKey, to, references, type';
    case RelationEntryKey = 'Invalid relation entry #%s in '
        . 'information_schema.json: key "%s" is missing or not a string; '
        . 'expected keys: from, foreignKey, to, references, type; actual '
        . 'keys: %s';
    case RelationEntryType = 'Invalid relation entry #%s in '
        . 'information_schema.json: unknown type %s; allowed types: '
        . 'belongsTo, hasMany, hasOne';
    case RelationEntryBackingIndex = 'Invalid relation entry #%s in '
        . 'information_schema.json: the "backingIndex" key is not a string';

    case TypeMismatch = 'Table "%s", column "%s": expected type %s, got %s';
    case NullNotAllowed = 'Table "%s", column "%s" does not accept null, but '
        . 'null was given';
    case RequiredColumnMissing = 'Table "%s": the required column "%s" is '
        . 'missing';
    case NonFiniteFloat = 'Table "%s", column "%s": NAN and INF cannot be '
        . 'stored in a float column';
    case InvalidUtf8 = 'Table "%s", column "%s": the string value is not '
        . 'valid UTF-8';
    case InvalidTemporalValue = 'Table "%s", column "%s": invalid %s value: '
        . '"%s"';
    case ZeroDate = 'Table "%s", column "%s": zero dates are not allowed: '
        . '"%s"';
    case TemporalFractionUnsupported = 'Table "%s", column "%s": %s does not '
        . 'accept the given sub-second precision: "%s"';
    case NumericPartOutOfRange = 'Table "%s", column "%s": the %s value is '
        . 'out of range: %s';
    case UniqueViolation = 'Unique constraint violation in table "%s" on '
        . 'fields [%s]: %s';
    case IndexKeyNonFinite = 'Index key for field "%s": NAN and INF cannot '
        . 'be indexed';
    case RecordColumnNoDefault = 'Invalid record in table "%s": column "%s" '
        . 'of type %s is missing and the type has no safe default';
    case RecordColumnNoDefaultManual = 'Invalid record in table "%s": column '
        . '"%s" of type %s is missing and the type has no safe default; fix '
        . 'it by hand';
    case RecordArchivedColumnNoDefault = 'Invalid record in table "%s": '
        . 'column "%s" of type %s is missing from an archived record and the '
        . 'type has no safe default; fix it by hand';
    case RecordTailNotTerminated = 'Invalid record in table "%s": the tail '
        . 'of the data file does not end with a line break; repair the table '
        . 'first';
    case RecordJsonEncodeFailed = 'Invalid record in table "%s": JSON '
        . 'encoding failed: %s';
    case RecordImportIdInvalid = 'Invalid record in table "%s": bulk import '
        . 'requires every record to carry a positive integer id, got %s';
    case RecordImportIdDuplicate = 'Invalid record in table "%s": bulk '
        . 'import carries id %s more than once';

    case QueryUnknownColumn = 'Table "%s" has no column "%s" (referenced in '
        . '"%s")';
    case InvalidOperator = 'Table "%s": unknown filter operator "%s"';
    case InvalidFilterOperator = 'Unknown filter operator "%s"';
    case InvalidSortDirection = 'Table "%s": invalid sort direction "%s" '
        . '(expected "asc" or "desc", case-insensitive)';
    case InvalidLimit = 'Table "%s": the row limit must be a non-negative '
        . 'integer, got %s';
    case InvalidOffset = 'Table "%s": the row offset must be a non-negative '
        . 'integer, got %s';
    case LikeOnInstantUnsupported = 'Table "%s", column "%s": LIKE is not '
        . 'supported on a datetime/datetimez column, as the value is stored '
        . 'in UTC; use comparison operators or BETWEEN';
    case LikeEvaluationFailed = 'The LIKE pattern "%s" could not be '
        . 'evaluated: %s';
    case ConditionBetweenShape = 'Table "%s", column "%s", operator %s: '
        . 'expects a [min, max] array of two non-null scalars';
    case ConditionInShape = 'Table "%s", column "%s", operator %s: expects '
        . 'an array of scalars';
    case ConditionExpectsType = 'Table "%s", column "%s", operator %s: the '
        . 'condition value must be %s, got %s';
    case ConditionExpectsScalar = 'Table "%s", column "%s", operator %s: the '
        . 'condition value must be a scalar, got %s';
    case ConditionExpectsNonNullScalar = 'Table "%s", column "%s", operator '
        . '%s: the condition value must be a non-null scalar, got %s';
    case ConditionExpectsFloatOrInt = 'Table "%s", column "%s", operator %s: '
        . 'the condition value must be a float or an int, got %s';
    case ConditionExpectsStringPattern = 'Table "%s", column "%s", operator '
        . '%s: the condition value must be a string pattern, got %s';
    case ConditionExpectsTemporalString = 'Table "%s", column "%s", operator '
        . '%s: the condition value must be a %s string, got %s';
    case LikeOnNonStringColumn = 'Table "%s", column "%s": LIKE is not '
        . 'defined for a %s column; use a string or temporal column';

    case DtoNotRegistered = 'No DTO is registered for table "%s"; work with '
        . 'arrays or register a record class for the table';
    case DtoAlreadyRegistered = 'Table "%s" already has the DTO %s '
        . 'registered; release the current binding before binding %s';
    case InvalidEnumValue = 'Table "%s", column "%s": the stored value "%s" '
        . 'is not a valid case of %s';
    case DtoNoConstructor = 'DTO %s does not match table "%s": a mapped DTO '
        . 'must declare a constructor';
    case DtoMissingAttribute = 'DTO %s does not match table "%s": the '
        . '#[JsonProviderRecord] attribute is missing';
    case DtoBoundToOtherTable = 'DTO %s does not match table "%s": it is '
        . 'bound to table "%s"';
    case DtoPropertyTypeMismatch = 'DTO %s does not match table "%s": the '
        . 'value of property "%s" is not of its declared type';
    case DtoPropertyUntyped = 'DTO %s does not match table "%s", property '
        . '"%s": the property has no type declaration, but a mapped property '
        . 'must be typed';
    case DtoPropertyUnionType = 'DTO %s does not match table "%s", property '
        . '"%s": union types are not supported';
    case DtoPropertyIntersectionType = 'DTO %s does not match table "%s", '
        . 'property "%s": intersection types are not supported';
    case DtoPropertyMixedType = 'DTO %s does not match table "%s", property '
        . '"%s": "mixed" is not supported, declare a concrete type';
    case DtoPropertyUnsupportedType = 'DTO %s does not match table "%s", '
        . 'property "%s": unsupported type declaration';
    case DtoPropertyColumnDerivedMiss = 'DTO %s does not match table "%s", '
        . 'property "%s": the table has no column "%s" derived from it; set '
        . 'the name explicitly with #[JsonProviderColumn("...")]';
    case DtoPropertyColumnMiss = 'DTO %s does not match table "%s", property '
        . '"%s": the table has no column "%s"';
    case DtoPropertyTemporalOnly = 'DTO %s does not match table "%s", '
        . 'property "%s": DateTimeImmutable maps only to a date/time column, '
        . 'got "%s"';
    case DtoPropertyScalarIncompatible = 'DTO %s does not match table "%s", '
        . 'property "%s": the PHP type "%s" is not compatible with the '
        . 'column type "%s"';
    case DtoPropertyEnumBacking = 'DTO %s does not match table "%s", '
        . 'property "%s": the enum %s (backed by %s) is not compatible with '
        . 'the column type "%s"';
    case DtoPropertyEnumUnbacked = 'DTO %s does not match table "%s", '
        . 'property "%s": the enum has no backing type';
    case DtoPropertyInheritedPrivate = 'DTO %s does not match table "%s", '
        . 'property "%s": a private property inherited from %s is not '
        . 'readable by the mapper; declare it on the DTO class itself or '
        . 'make it protected';
    case DtoPropertyColumnIsNullable = 'DTO %s does not match table "%s", '
        . 'property "%s": the column accepts null, the property does not';
    case DtoPropertyColumnIsNotNullable = 'DTO %s does not match table "%s", '
        . 'property "%s": the property accepts null, the column does not';
    case HydrateNullNonNullable = 'Table "%s", column "%s": cannot build the '
        . 'DTO — a stored null for a property that does not accept null';
    case HydrateNotTemporal = 'Table "%s", column "%s": cannot build the DTO '
        . '— a stored %s for a date/time property';
    case HydrateBadTemporal = 'Table "%s", column "%s": cannot build the DTO '
        . '— the stored value "%s" is not a valid %s';
    case DtoIdMustBeInt = 'Table "%s": updating by object requires an '
        . 'integer id on the DTO';

    case BackupSourceInvalid = 'Backup aborted: integrity validation found '
        . '%s problem(s) of error severity or worse; nothing was written';
    case BackupDestinationNotWritable = 'The backup was not written: the '
        . 'directory %s does not exist or is not writable';
    case BackupWriteFailed = 'The backup was not written to %s: %s';
    case BackupArchiveExists = 'The backup archive already exists at: %s';
    case BackupDestinationInsideDb = 'The backup destination must lie '
        . 'outside the database directory: %s';
    case BackupChecksumMismatch = 'The archive member "%s" failed the '
        . 'integrity checksum; the archive is corrupt or was tampered with';
    case ArchiveNotFound = 'The backup archive was not found: %s';
    case ArchiveNotReadable = 'The backup archive is not readable: %s';
    case ArchiveOpenFailed = 'The backup archive cannot be opened: %s';
    case ArchiveManifestMissing = 'The backup archive is invalid: it has no '
        . 'manifest.json';
    case ArchiveManifestUnreadable = 'The backup archive is invalid: '
        . 'manifest.json is unreadable';
    case ArchiveManifestNotJson = 'The backup archive is invalid: '
        . 'manifest.json is not valid JSON';
    case ArchiveManifestFormat = 'The backup archive is invalid: unexpected '
        . 'manifest format "%s"';
    case ArchiveManifestVersion = 'The backup archive is invalid: '
        . 'unsupported manifest version %s';
    case ArchiveMemberUnreadable = 'The backup archive is invalid: a member '
        . 'with a declared checksum is missing or unreadable: %s';
    case ArchiveSchemaUnreadable = 'The backup archive is invalid: '
        . 'information_schema.json is missing or unreadable';
    case ArchiveSchemaNotJson = 'The backup archive is invalid: '
        . 'information_schema.json is not valid JSON';
    case ArchiveEntryMissing = 'The backup archive is invalid: the entry is '
        . 'missing: %s';
    case ArchiveEntryUnreadable = 'The backup archive is invalid: the entry '
        . 'is unreadable: %s';
    case RestoreLocalTablesAbsent = 'The archive schema does not match the '
        . 'current database schema: the database has tables absent from the '
        . 'archive: %s; pass pruneExtraTables=true to drop them while '
        . 'restoring with the archive schema';
    case RestoreArchiveMissesTables = 'The archive schema does not match the '
        . 'current database schema: the archive is missing tables required '
        . 'by the current schema: %s';
    case RestoreArchiveExtraTables = 'The archive schema does not match the '
        . 'current database schema: the archive holds tables not declared in '
        . 'the current schema: %s';
    case RestoreRolledBack = 'The restore failed and was rolled back; '
        . 'original error: %s';
    case RestoreRollbackFailed = 'The restore failed: %s; the rollback '
        . 'failed too: %s; the snapshot is kept at: %s';
    case MetaEntryMissing = 'meta.json has no entry for table "%s" — the '
        . 'table is not registered with the provider';
    case MetaEntryNotObject = 'The meta entry for table "%s" is corrupt: the '
        . 'entry is not an object — run a database repair to rebuild the '
        . 'counters from the data';
    case MetaCounterNotInt = 'The meta entry for table "%s" is corrupt: a '
        . 'counter field is missing or not an integer — run a database '
        . 'repair to rebuild the counters from the data';
    case IndexFileMissing = 'Index "%s" of table "%s" is structurally '
        . 'corrupt and cannot be trusted: the index file is missing';
    case IndexCountMismatch = 'Index "%s" of table "%s" is structurally '
        . 'corrupt and cannot be trusted: the entry count %s does not match '
        . 'the line count %s';
    case IndexEntryMalformed = 'Index "%s" of table "%s" is structurally '
        . 'corrupt and cannot be trusted: a malformed index entry';
    case IndexBrokenPermutation = 'Index "%s" of table "%s" is structurally '
        . 'corrupt and cannot be trusted: the line references are not a '
        . 'permutation of the data lines';
    case IndexKeyMalformed = 'Index "%s" of table "%s" is structurally '
        . 'corrupt and cannot be trusted: a truncated or malformed key';
    case IndexLinesMissing = 'Index "%s" of table "%s" is structurally '
        . 'corrupt and cannot be trusted: indexed lines are missing from the '
        . 'data file';
    case ExtensionRequired = 'A PHP extension is required to use this cache '
        . 'adapter: %s';

    public static function translate(
        string $key,
        string ...$params,
    ): string {
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
