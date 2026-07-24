# Schema mutations — reorder, migrate, indexes, unique, rename, drop

Once a table exists its structure can still evolve: columns via `migrateColumns`/`reorderColumns`/`renameColumn`, indexes via `addIndex`/`dropIndex`, unique constraints via `addUniqueConstraint`/`dropUniqueConstraint`, the table name via `renameTable`, plus a few read-only helpers to inspect what is registered.

All DDL operations run under exclusive locks on the database and the affected tables, and re-read the schema from disk as their first step — concurrent schema changes from other processes are never lost, and a race of same-name `createTable` calls honestly ends with `TABLE_ALREADY_EXISTS` for the loser.

`createTable` works in "schema → meta → files" order: the data and index files are created last and provisioned fresh (garbage from a crashed drop with the same name never leaks into the new table). A crash mid-operation leaves a registered table without meta/files — that window self-heals on the first write into the table, or via an explicit `repairTable()`.

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
- indexes are rebuilt by the standard full rewrite (writeAll) with their logical content unchanged — column key order in records does not affect index keys; the current `indexFormat` is stamped along the way.

The schema is updated first, then records are rewritten. If the second step fails, the table's record order will be repaired by the next mutation or by an explicit `repairTable()`.

## migrateColumns — add / drop / reorder columns

`migrateColumns()` aligns the **columns** of an existing table to a target `TableSchema`: it adds, drops and reorders columns in one pass and rewrites the data file. The operation is about columns only: the table's indexes, unique constraints and comments stay exactly as they are; `indexes`/`uniqueConstraints` carried by the target schema are ignored — index and key structures change only through `addIndex`/`dropIndex`/`addUniqueConstraint`/`dropUniqueConstraint`. It returns the columns it added and dropped.

```php
$result = $db->migrateColumns(TableSchema::create(
    name: 'users',
    columns: [
        'email' => ColumnTypes::STRING,
        'name'  => ColumnTypes::STRING,
        'phone' => ColumnTypes::STRING_NULLABLE,  // new column
        // 'isActive' is dropped by omission
    ],
));
// $result === ['added' => ['phone'], 'dropped' => ['isActive']]
```

What it does:

- **adds** columns present in the target but not stored — existing rows get a type-appropriate default: `''`, `0`, `0.0`, `false`, or `null` for a nullable type (the single source of defaults is `Schema\ColumnDefaults`);
- **drops** columns present in the table but not in the target — their values are removed from every row (a dropped column's comment goes with it);
- **reorders** columns to match the target.

It is a no-op (returns empty `added`/`dropped`) when the column set and order already match.

Guards — each throws a `StorageException` and writes nothing:

- the table does not exist → `TABLE_NOT_FOUND`;
- a retained column changes type → `MIGRATE_COLUMN_TYPE_CHANGE`. `migrateColumns` never re-encodes stored values, so a type change (including turning a column nullable) is out of its contract — see the recipe below;
- a not-null column with no zero-value default (`date` / `time` / `datetime`, `year` / `month` / `day`) is added to a **non-empty** table → `MIGRATE_COLUMN_NO_DEFAULT`. Declare it nullable, or add it while the table is still empty;
- an existing unique constraint or index of the table references a column being dropped → `MIGRATE_FIELD_UNKNOWN_COLUMN` — drop the index/constraint first (`dropIndex`/`dropUniqueConstraint`).

Crash safety: the full migrated record set is **encoded into a buffer before** any mutation — an unencodable stored value (foreign bytes in the file) aborts the operation with the disk untouched (`INVALID_RECORD`). Then the schema is published and only then are the data rewritten (temp+fsync+rename), indexes rebuilt, meta committed and the cache refreshed. A crash between the schema and the data is healed by `repair()` toward the **target** state: added not-null columns are back-filled with their type defaults, not null.

### Changing a column's type — recipe

No mutation implements a type change (the loud `MIGRATE_COLUMN_TYPE_CHANGE`). The path: add a new nullable column of the desired type → move the values over with `update`s → drop the old column (`dropIndex`/`dropUniqueConstraint` if needed, then `migrateColumns`) → `renameColumn` the new column to the old name.

## renameColumn — rename a column

`renameColumn()` renames a column everywhere at once: the schema key (same position and type), index and unique-constraint fields, the column comment, relation sides (the child-side `foreignKey` and the parent-side `references`) and the stored data. A bound DTO is recompiled against the new schema; if the DTO still references the old name, the binding is dropped and object reads fail with a loud `DTO_NOT_REGISTERED` instead of silently broken hydration.

```php
$db->renameColumn('users', 'phone', 'phone_number');
```

Guards (nothing is written): unknown column → `COLUMN_NOT_FOUND`; `id` cannot be renamed → `PK_CONTRACT_VIOLATED`; invalid new name → `INVALID_COLUMN_NAME`; taken → `COLUMN_ALREADY_EXISTS`.

The crash model matches `migrateColumns` (schema before data) with one caveat: after a crash `repair()` converges to the **new** schema by back-filling the renamed column with its type default rather than carrying the old values over — take a backup first if the column data matters.

## addIndex / dropIndex — indexes

`addIndex()` adds a secondary index and builds its file from current data; `dropIndex()` removes the index from the schema and deletes the file. Both run under the database + table EX locks.

```php
$db->addIndex('users', new IndexSchema(
    'idx_users_email',
    [new IndexFieldSchema('email', SortDirectionEnum::ASC)],
));
$db->dropIndex('users', 'idx_users_email');
```

- a taken name → `INDEX_ALREADY_EXISTS`; a field outside the columns → `MIGRATE_FIELD_UNKNOWN_COLUMN`; the PK index cannot be added or dropped → `PK_CONTRACT_VIOLATED`; an unknown name on dropIndex → `INDEX_NOT_FOUND`;
- `addIndex`: the file is provisioned and built first, the schema published last — a crash in between leaves an undeclared file that `validate()` reports as orphan and `repair()` removes. On a legacy-format table every index is rebuilt with the current codec and `indexFormat=2` is stamped;
- `dropIndex`: schema first, then the file — a crash in between leaves an orphan file with the same fate.

## addUniqueConstraint / dropUniqueConstraint — unique constraints

`addUniqueConstraint()` pre-checks existing data before publishing (type-strict keys, SQL NULL semantics: records with `null` in a key field do not participate): a stored duplicate → `UNIQUE_VIOLATION` with the schema unchanged. `dropUniqueConstraint()` lifts the constraint (schema-only).

```php
$db->addUniqueConstraint('users', new UniqueConstraint('uq_users_email', ['email']));
$db->dropUniqueConstraint('users', 'uq_users_email');
```

A taken name → `UNIQUE_CONSTRAINT_ALREADY_EXISTS`; a field outside the columns → `MIGRATE_FIELD_UNKNOWN_COLUMN`; an unknown name on drop → `UNIQUE_CONSTRAINT_NOT_FOUND`.

## renameTable — rename a table

`renameTable()` moves the schema key, every relation referencing the table, the meta entry (counters preserved) and the physical directory + data file to the new name; index files are not renamed (they are named after the index). Locks: database EX plus table EX on **both** names.

```php
$db->renameTable('users', 'accounts');
```

Guards: `$from` does not exist → `TABLE_NOT_FOUND`; `$to` invalid → `INVALID_TABLE_NAME`; `$to` taken in the schema, in meta or on disk → `TABLE_ALREADY_EXISTS`.

Crash model: a `_pendingRename` marker `{from, to}` is written to `meta.json` FIRST, then the schema with its relations commits atomically (one RMW transaction), then meta and the filesystem follow, and the marker is cleared last. `repair()` reconciles a leftover marker **by the actual schema state**: schema already on the new name — meta and the filesystem are rolled forward under it; schema still on the old name — the rename never committed, only the marker is dropped. `validate()` reports the marker as `rename_incomplete` (error).

## dropTable — remove a table

`dropTable()` removes a table completely: its schema entry (with every relation that involves it), its meta entry, its files and any bound DTO mapping. It is idempotent — an unknown table is a no-op.

```php
$db->dropTable('audit_log');
```

Notes:

- the operation runs under exclusive database and table locks; the schema entry is removed first (schema → meta → files), so the "every registered table has a meta entry" invariant is never broken mid-operation;
- foreign keys are **not** enforced: dropping a parent table silently removes its relations and leaves any child FK columns/values dangling (like SQL `DROP TABLE`, not `DROP TABLE ... RESTRICT`). Drop or migrate the children first if that matters.

## Introspection

Read-only helpers to see what is registered — handy when writing idempotent migrations:

```php
$db->hasTable('users');       // bool — is the table registered?
$db->tableNames();            // list<string> — all registered table names
$db->columnNames('users');    // list<string> — columns in schema order (id first)
```
