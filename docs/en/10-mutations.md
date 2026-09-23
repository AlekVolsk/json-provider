# Mutations — insert / update / delete

## Insert

```php
$id = $db->table('products')->insertByArray([
    'name'  => 'Widget',
    'price' => 9.99,
]);   // int — assigned id
```

The `id` is allocated by the provider (`lastInsertedId + 1`) and **not reused** after delete. Fields not declared in the schema are silently dropped. A nullable column missing from the payload becomes `null`; a missing non-nullable one raises `RequiredColumnMissing` (the full value contract lives in the [Schema model](04-schema-model.md)).

Unique constraints are checked before write. Violation throws `JsonProviderDataException` with `UniqueViolation`.

## Update

DML methods return `bool`. `true` means "the operation completed" — an empty match set is **not** an error.

Bulk update by condition:

```php
$ok = $db->table('products')
    ->where('categoryId', '=', $cat)
    ->where('active', '=', true)
    ->updateByArray(['price' => 0, 'active' => false]);
```

Single-record update by id:

```php
$ok = $db->table('products')->updateByIdByArray($id, ['price' => 9.99]);
```

The `id` field is silently ignored in update payloads — it cannot be changed.

### Which rows are updated

An array and a DTO select rows differently, because the data itself differs:

- **`updateByArray($data)`** — the array is arbitrary and need not carry `id` (and if it does, `id` is dropped), so rows are selected by the **accumulated `where`**: the patch is applied to every matching record. **Without `where`, every record of the table is updated.** `updateByIdByArray($id, $data)` is `where('id', '=', $id)->updateByArray($data)`.
- **`update($dto)`** — a DTO always carries `id`, and it selects the row: exactly the record with that `id` is updated, the accumulated `where` is ignored (and reset, as after any terminal operation). The other columns are overwritten with the DTO's values — see [DTO mapping](21-dto-mapping.md#the-object-methods).

The number of affected rows is available from `affectedRows()` — see [below](#affected-rows).

## Delete

```php
$db->table('products')->where('discontinued', '=', true)->delete();
$db->table('products')->deleteById($id);
```

Foreign-key actions (`cascade`, `setNull`, `restrict`) trigger automatically based on the schema. `restrict` raises `ForeignKeyRestrict` if there are children pointing at the row.

## How FK actions execute

The whole multi-table effect of a delete/update is planned **before the first byte hits the disk** (the plan phase), then applied two-phase:

- **Plan.** The engine walks the relations graph breadth-first from the affected root rows, reading every affected table from disk exactly once. All validation happens here: executable-edge semantics (columns/types — `RelationColumnNotFound`/`RelationTypeMismatch`), the setNull nullability guarantee (`ForeignKeySetNullNotNullable`), restrict probes over the backing index, cascade patch typing through the same codec insert/update use, and unique checks of the batch's **final** state (two targets collapsing into one unique key are caught before any write). Any plan error leaves the disk untouched.
- **Cascades and cycles.** A row already collected for deletion is never re-expanded — A↔B cycles and self-referential chains terminate and are deleted in full (transitive descendants do not "resurrect").
- **Restrict is MySQL-immediate.** The probe runs against the ORIGINAL child state: a violation counts even when the referencing child is deleted by the same statement. A self-referential restrict table cannot be emptied by one delete-all — delete leaves before roots.
- **Apply (PREPARE/COMMIT).** First every table of the plan is encoded and written to a fsynced temp file (any failure — e.g. an unencodable row — aborts the whole set with the data files untouched), then the files are flipped by renames **children before parents**, after which each table gets its indexes rebuilt, meta counters committed and the cache refreshed. A crash between renames leaves every table individually complete (old or new full file); re-running the same statement converges — thanks to the children-first order, already-deleted children cannot turn into invisible orphans.

`onUpdate` cascades work only for relations referencing a non-PK unique column (see [relations](04-schema-model.md)) and propagate transitively: updating a child's FK column that grandchildren reference continues the closure. This includes `setNull` on **delete**: nulling the child FK column is a value change the grandchildren's onUpdate edges observe — a restrict grandchild blocks the delete, cascade/setNull grandchildren follow the null.

## Bulk load

```php
$written = $db->importRecords('products', $rows);   // int — records written
```

Replaces the whole content of a table in one rewrite — the bulk counterpart of `insert()`, for seeding, imports and restores. Every record goes through the same normalization and validation `insert()` uses, unique constraints are checked across the whole batch (a duplicate raises `UniqueViolation`), then the data file is atomically replaced, indexes are rebuilt, meta counters (`lineCount`/`byteSize`) are committed and the cache is republished. The table ends up in exactly the state a sequence of `insert()` calls would leave, at a fraction of the cost.

Ids are required on every record and must be unique within the batch: this is a load of known records, not a sequence of appends. Violations raise `RecordImportIdInvalid` and `RecordImportIdDuplicate`. The auto-increment counter is raised to the largest supplied id and never lowered: the next `insert()` continues past the imported rows, and ids of the replaced rows are never reissued.

Validation completes before anything is written: a bad row in the middle of the batch aborts the import with the table untouched rather than half-replaced.

Foreign-key actions are **not** enforced (symmetric with `truncate` and `dropTable`): import the parent side first, or run `validate()` if the source is untrusted.

## Truncate

```php
$db->truncate('audit_log');
```

SQL `TRUNCATE` semantics: every record is removed, indexes are rebuilt empty, and the auto-increment counter resets to 0 — the next insert gets `id = 1` (a delete-all, unlike truncate, keeps the counter). Foreign-key actions are **not** enforced (symmetric with `dropTable`): dangling child FK values remain — handle the children first if that matters. An unknown table → `TableNotFound`.

## Renames

Renaming a column (`renameColumn`) and a table (`renameTable`) are DDL operations described in [Schema mutations](12-schema-mutations.md): both carry the data, indexes, relations and meta over to the new name and are crash-protected (for `renameTable` — by the `_pendingRename` marker that `repair()` reconciles).

## Affected rows

```php
$tbl = $db->table('products');
$tbl->where('discontinued', '=', true)->delete();
echo $tbl->affectedRows();   // last DML row count
```

`affectedRows()` is `0` until the first DML on this builder, and afterwards holds the count of the latest DML until the next one: reading it does not reset it, and read operations (`select*`, `count`, `exists`, identifier APIs) never change it.
