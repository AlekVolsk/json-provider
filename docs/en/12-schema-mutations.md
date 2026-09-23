# Schema mutations — reorder, migrate, indexes, unique, rename, drop

Once a table exists its structure can still evolve: columns via `migrateColumns`/`reorderColumns`/`renameColumn`, indexes via `addIndex`/`dropIndex`, unique constraints via `addUniqueConstraint`/`dropUniqueConstraint`, the table name via `renameTable`, plus a few read-only helpers to inspect what is registered.

All DDL operations run under exclusive locks on the database and the affected tables, and re-read the schema from disk as their first step — concurrent schema changes from other processes are never lost, and a race of same-name `createTable` calls honestly ends with `TableAlreadyExists` for the loser.

`createTable` works in "schema → meta → files" order: the data and index files are created last and provisioned fresh (garbage from a crashed drop with the same name never leaks into the new table). A crash mid-operation leaves a registered table without meta/files — that window self-heals on the first write into the table, or via an explicit `repairTable()`.

## reorderColumns

`reorderColumns()` changes the order of columns of an existing table. It rewrites both the schema and every record so they agree on the new order.

```php
$db->table('products')->reorderColumns(['name', 'price', 'category_id']);
```

Behaviour:

- unknown columns in `$newOrder` → `ReorderColumnsUnknown`;
- duplicate columns → `ReorderColumnsDuplicate`;
- if `id` is missing in `$newOrder` it is prepended automatically;
- if `id` is present but not first, it is moved to position 0;
- after the id-normalization the list must contain every existing column, otherwise → `ReorderColumnsIncomplete`;
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

Guards — each throws a provider exception and writes nothing:

- the table does not exist → `TableNotFound`;
- a retained column changes type → `MigrateColumnTypeChange`. `migrateColumns` never re-encodes stored values, so a type change (including turning a column nullable) is out of its contract — see the recipe below;
- a not-null column with no zero-value default (any temporal one — `date` / `time` / `timez` / `datetime` / `datetimez` — plus `year` / `month` / `day`) is added to a **non-empty** table → `MigrateColumnNoDefault`. Declare it nullable, or add it while the table is still empty;
- an existing unique constraint or index of the table references a column being dropped → `JsonProviderSchemaException` — drop the index/constraint first (`dropIndex`/`dropUniqueConstraint`);
- a column being dropped is a side of a declared relation (the child FK column or the parent referenced column) → `JsonProviderSchemaException` with a hint — `dropRelation` first. Without this guard a setNull relation (which has no backing index to block the drop) would leave every parent delete failing with `RelationColumnNotFound`.

Crash safety: the full migrated record set is **encoded into a buffer before** any mutation — an unencodable stored value (foreign bytes in the file) aborts the operation with the disk untouched (`JsonProviderDataException`). Then the schema is published and only then are the data rewritten (temp+fsync+rename), indexes rebuilt, meta committed and the cache refreshed. A crash between the schema and the data is healed by `repair()` toward the **target** state: added not-null columns are back-filled with their type defaults, not null.

### Changing a column's type — recipe

No mutation implements a type change (the loud `MigrateColumnTypeChange`). The path: add a new nullable column of the desired type → move the values over with `update`s → drop the old column (`dropIndex`/`dropUniqueConstraint` if needed, then `migrateColumns`) → `renameColumn` the new column to the old name.

## renameColumn — rename a column

`renameColumn()` renames a column everywhere at once: the schema key (same position and type), index and unique-constraint fields, the column comment, relation sides (the child-side `foreignKey` and the parent-side `references`) and the stored data. A service FK index of the renamed column follows with its NAME (`_fk_<old>` → `_fk_<new>`, the file is rebuilt and the relations' `backingIndex` re-pointed) — the service index name encodes the column. A bound DTO is recompiled against the new schema; if the DTO still references the old name, the binding is dropped and object reads fail with a loud `DtoNotRegistered` instead of silently broken hydration.

```php
$db->renameColumn('users', 'phone', 'phone_number');
```

Guards (nothing is written): unknown column → `ColumnNotFound`; `id` cannot be renamed → `JsonProviderSchemaException`; invalid new name → `InvalidColumnName`; taken → `ColumnAlreadyExists`.

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

- a taken name → `IndexAlreadyExists`; a field outside the columns → `IndexUnknownColumn`; the PK index cannot be added or dropped → `JsonProviderSchemaException`; an unknown name on dropIndex → `IndexNotFound`; service `_fk_` indexes cannot be created or dropped through this API → `ReservedIndexName`;
- `addIndex`: the file is provisioned and built first, the schema published last — a crash in between leaves an undeclared file that `validate()` reports as orphan and `repair()` removes. On a table below the current format every index is rebuilt with the current codec and `indexFormat=2` is stamped;
- `dropIndex`: schema first, then the file — a crash in between leaves an orphan file with the same fate. When the user index being dropped serves as the backing of an FK relation, a service replacement `_fk_<column>` is built in the same schema change and the relation is re-pointed (see [indexes](06-indexes.md)).

## addUniqueConstraint / dropUniqueConstraint — unique constraints

`addUniqueConstraint()` pre-checks existing data before publishing (type-strict keys, SQL NULL semantics: records with `null` in a key field do not participate): a stored duplicate → `UniqueViolation` with the schema unchanged. `dropUniqueConstraint()` lifts the constraint (schema-only).

```php
$db->addUniqueConstraint('users', new UniqueConstraint('uq_users_email', ['email']));
$db->dropUniqueConstraint('users', 'uq_users_email');
```

A taken name → `UniqueConstraintAlreadyExists`; a field outside the columns → `UniqueConstraintUnknownColumn`; an unknown name on drop → `UniqueConstraintNotFound`. A single-column constraint grounding the uniqueness of a declared relation's referenced column cannot be dropped while the relation lives (and no other single-column unique on the same column remains) → `RelationReferencesNotUnique`: with duplicates allowed in the parent column, a cascade would delete the children of a still-living duplicate parent. `dropRelation` first.

## addRelation / dropRelation — relations

`addRelation()` declares an FK relation (declaration validation is described in [the schema model](04-schema-model.md)); for probing actions (`cascade`/`restrict`) the same operation provisions the child-table backing index — a single-column user index on the FK column is reused, otherwise a service `_fk_<column>` is built. `dropRelation()` removes the relation by its declared `(from, foreignKey, to)` triple; a service backing with no other probing relations is dropped with it, a user one stays. Both are DDL under the database + child table EX locks; the relation is active immediately.

```php
$db->addRelation(new RelationSchema(
    fromTable: 'boards',
    foreignKey: 'ownerId',
    toTable: 'users',
    references: 'id',
    type: RelationTypeEnum::BELONGS_TO,
    onDelete: ForeignKeyActionEnum::CASCADE,
));
$db->dropRelation('boards', 'ownerId', 'users');
$db->relations();           // every relation
$db->relations('users');    // relations involving users
```

A duplicate of the canonical edge (in either notation) → `RelationAlreadyExists`; dropping a missing one → `RelationNotFound`. The `backingIndex` field of the passed descriptor is ignored — the engine owns it.

Crash model of `addRelation` with a service index: the index file is built first, then one RMW publishes the index in the child schema, then (a separate RMW) the relation itself. A crash between the steps leaves either an orphan `_fk_*.index.ndjson` file (removed by `repair()`) or a declared but not yet referenced service index — harmless, reused by a repeated `addRelation`.

## renameTable — rename a table

`renameTable()` moves the schema key, every relation referencing the table, the meta entry (counters preserved) and the physical directory + data file to the new name; index files are not renamed (they are named after the index). Locks: database EX plus table EX on **both** names.

```php
$db->renameTable('users', 'accounts');
```

Guards: `$from` does not exist → `TableNotFound`; `$to` invalid → `InvalidTableName`; `$to` taken in the schema, in meta or on disk → `TableAlreadyExists`.

Crash model: a `_pendingRename` marker `{from, to}` is written to `meta.json` FIRST, then the schema with its relations commits atomically (one RMW transaction), then meta and the filesystem follow, and the marker is cleared last. `repair()` reconciles a leftover marker **by the actual schema state**: schema already on the new name — meta and the filesystem are rolled forward under it; schema still on the old name — the rename never committed, only the marker is dropped. `validate()` reports the marker as `rename_incomplete` (error).

## dropTable — remove a table

`dropTable()` removes a table completely: its schema entry (with every relation that involves it), its meta entry, its files and any bound DTO mapping. It is idempotent — an unknown table is a no-op.

```php
$db->dropTable('audit_log');
```

Notes:

- the operation runs under exclusive database and table locks; the schema entry is removed first (schema → meta → files), so the "every registered table has a meta entry" invariant is never broken mid-operation;
- foreign keys are **not** enforced: dropping a parent table silently removes its relations and leaves any child FK columns/values dangling (like SQL `DROP TABLE`, not `DROP TABLE ... RESTRICT`). Drop or migrate the children first if that matters;
- service backing indexes the removed relations provisioned in their **child** tables are released with them (the children are EX-locked for that); a service index orphaned for any other reason is caught by the validator as `fk_backing_index_orphaned` and removed by `repair()`.

## Introspection

Read-only helpers to see what is registered — handy when writing idempotent migrations:

```php
$db->hasTable('users');       // bool — is the table registered?
$db->tableNames();            // list<string> — all registered table names
$db->columnNames('users');    // list<string> — columns in schema order (id first)
```
