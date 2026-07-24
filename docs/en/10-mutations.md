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

A limitation of the current cascade engine: with cyclic relations (A↔B) and self-referencing tables a cascading delete may fail to remove transitive descendants (only the direct target is removed). Do not rely on cascades over cyclic graphs — delete leaves-to-roots instead.

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
