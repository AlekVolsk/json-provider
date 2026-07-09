# Schema mutations — reorder, migrate, drop

Once a table exists its structure can still evolve. Three operations cover the common cases, plus a few read-only helpers to inspect what is registered.

## reorderColumns

`reorderColumns()` changes the order of columns of an existing table. It rewrites both the schema and every record so they agree on the new order.

```php
$db->table('products')->reorderColumns(['name', 'price', 'category_id']);
```

Behaviour:

- unknown columns in `$newOrder` → `REORDER_COLUMNS_UNKNOWN`;
- duplicate columns → `REORDER_COLUMNS_DUPLICATE`;
- if `id` is missing in `$newOrder` it is prepended automatically;
- if `id` is present but not first, it is moved to position 0;
- after the id-normalization the list must contain every existing column, otherwise → `REORDER_COLUMNS_INCOMPLETE`;
- indexes are not rebuilt — column key order in records does not affect index file contents. If you want a forced rebuild, call `rebuildAllIndexes()` separately.

The schema is updated first, then records are rewritten. If the second step fails, the table's record order will be repaired by the next mutation or by an explicit `repairTable()`.

## migrateColumns — add / drop / reorder columns

`migrateColumns()` aligns an existing table to a target `TableSchema`: it adds, drops and reorders columns in one pass, rewrites the data file, keeps the index files in sync and rebuilds every index. It returns the columns it added and dropped.

```php
$result = $db->migrateColumns(TableSchema::create(
    name: 'users',
    columns: [
        'email' => ColumnTypes::STRING,
        'name'  => ColumnTypes::STRING,
        'phone' => ColumnTypes::STRING_NULLABLE,  // new column
        // 'isActive' is dropped by omission
    ],
    indexes: [
        new IndexSchema('idx_users_email', [new IndexFieldSchema('email', SortDirectionEnum::ASC)]),
    ],
));
// $result === ['added' => ['phone'], 'dropped' => ['isActive']]
```

What it does:

- **adds** columns present in the target but not stored — existing rows get a type-appropriate default: `''`, `0`, `0.0`, `false`, or `null` for a nullable type;
- **drops** columns present in the table but not in the target — their values are removed from every row;
- **reorders** columns to match the target;
- **indexes**: an index file is created for an index new to the table and removed for one no longer present, then every index is rebuilt. Unique constraints and comments carried by the target become the table's new definitions.

It is a no-op (returns empty `added`/`dropped`) when the column set and order already match; in that case indexes, constraints and comments are left untouched — use `reorderColumns()` / the comment API for those, or change a column to re-sync the rest.

Guards — each throws a `StorageException` and writes nothing:

- the table does not exist → `TABLE_NOT_FOUND`;
- a retained column changes type → `MIGRATE_COLUMN_TYPE_CHANGE`. `migrateColumns` never re-encodes stored values, so a type change (including turning a column nullable) is out of its contract — migrate the data yourself;
- a not-null column with no zero-value default (`date` / `time` / `datetime`, `year` / `month` / `day`) is added to a **non-empty** table → `MIGRATE_COLUMN_NO_DEFAULT`. Declare it nullable, or add it while the table is still empty;
- a unique constraint or index in the target references a column the target does not declare → `MIGRATE_FIELD_UNKNOWN_COLUMN`.

Order of disk operations: new index files created, schema replaced, data and indexes rewritten, orphan index files deleted. The single-table cache is refreshed with the migrated rows.

## dropTable — remove a table

`dropTable()` removes a table completely: its schema entry (with every relation that involves it), its meta entry, its files and any bound DTO mapping. It is idempotent — an unknown table is a no-op.

```php
$db->dropTable('audit_log');
```

Notes:

- the schema entry is removed first, so the "every registered table has a meta entry" invariant is never broken mid-operation;
- foreign keys are **not** enforced: dropping a parent table silently removes its relations and leaves any child FK columns/values dangling (like SQL `DROP TABLE`, not `DROP TABLE ... RESTRICT`). Drop or migrate the children first if that matters.

## Introspection

Read-only helpers to see what is registered — handy when writing idempotent migrations:

```php
$db->hasTable('users');       // bool — is the table registered?
$db->tableNames();            // list<string> — all registered table names
$db->columnNames('users');    // list<string> — columns in schema order (id first)
```
