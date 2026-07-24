# Mutations — insert / update / delete

## Insert

```php
$id = $db->table('products')->insertByArray([
    'name'  => 'Widget',
    'price' => 9.99,
]);   // int — assigned id
```

The `id` is allocated by the provider (`lastInsertedId + 1`) and **not reused** after delete. Fields not declared in the schema are silently dropped. Schema fields missing from the payload become `null`.

Unique constraints are checked before write. Violation throws `StorageException` with `UNIQUE_VIOLATION`.

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

After an update, the builder remembers the affected row count for one read via `affectedRows()`.

## Delete

```php
$db->table('products')->where('discontinued', '=', true)->delete();
$db->table('products')->deleteById($id);
```

Foreign-key actions (`cascade`, `setNull`, `restrict`) trigger automatically based on the schema. `restrict` raises `FOREIGN_KEY_RESTRICT` if there are children pointing at the row.

## How FK actions execute

The whole multi-table effect of a delete/update is planned **before the first byte hits the disk** (the plan phase), then applied two-phase:

- **Plan.** The engine walks the relations graph breadth-first from the affected root rows, reading every affected table from disk exactly once. All validation happens here: executable-edge semantics (columns/types — `RELATION_COLUMN_NOT_FOUND`/`RELATION_TYPE_MISMATCH`), the setNull nullability guarantee (`FOREIGN_KEY_SET_NULL_NOT_NULLABLE`), restrict probes over the backing index, cascade patch typing through the same codec insert/update use, and unique checks of the batch's **final** state (two targets collapsing into one unique key are caught before any write). Any plan error leaves the disk untouched.
- **Cascades and cycles.** A row already collected for deletion is never re-expanded — A↔B cycles and self-referential chains terminate and are deleted in full (transitive descendants do not "resurrect").
- **Restrict is MySQL-immediate.** The probe runs against the ORIGINAL child state: a violation counts even when the referencing child is deleted by the same statement. A self-referential restrict table cannot be emptied by one delete-all — delete leaves before roots.
- **Apply (PREPARE/COMMIT).** First every table of the plan is encoded and written to a fsynced temp file (any failure — e.g. an unencodable row — aborts the whole set with the data files untouched), then the files are flipped by renames **children before parents**, after which each table gets its indexes rebuilt, meta counters committed and the cache refreshed. A crash between renames leaves every table individually complete (old or new full file); re-running the same statement converges — thanks to the children-first order, already-deleted children cannot turn into invisible orphans.

`onUpdate` cascades work only for relations referencing a non-PK unique column (see [relations](04-schema-model.md)) and propagate transitively: updating a child's FK column that grandchildren reference continues the closure. This includes `setNull` on **delete**: nulling the child FK column is a value change the grandchildren's onUpdate edges observe — a restrict grandchild blocks the delete, cascade/setNull grandchildren follow the null.

## Truncate

```php
$db->truncate('audit_log');
```

SQL `TRUNCATE` semantics: every record is removed, indexes are rebuilt empty, and the auto-increment counter resets to 0 — the next insert gets `id = 1` (a delete-all, unlike truncate, keeps the counter). Foreign-key actions are **not** enforced (symmetric with `dropTable`): dangling child FK values remain — handle the children first if that matters. An unknown table → `TABLE_NOT_FOUND`.

## Renames

Renaming a column (`renameColumn`) and a table (`renameTable`) are DDL operations described in [Schema mutations](12-schema-mutations.md): both carry the data, indexes, relations and meta over to the new name and are crash-protected (for `renameTable` — by the `_pendingRename` marker that `repair()` reconciles).

## Affected rows

```php
$tbl = $db->table('products');
$tbl->where('discontinued', '=', true)->delete();
echo $tbl->affectedRows();   // last DML row count
```

`affectedRows()` is `0` until the first DML on this builder. Read operations (`select*`, `count`, `exists`, identifier APIs) do not change it.
