# Index and table maintenance

## Index maintenance

Two operations let you preventively rebuild indexes without going through delete-and-recreate:

```php
$db->table('products')->rebuildIndex('idx_category');
$db->table('products')->rebuildAllIndexes();   // including PK
```

Both are atomic at the file level (each index file is replaced under an exclusive lock). Use them when you know an index is suspect — for example, after manual file tampering, or as a periodic maintenance task.

`rebuildIndex()` raises `IndexNotFound` if the named index does not exist in the table schema.

Both operations stamp the current `indexFormat` in meta; `rebuildIndex()` on a table with the old format escalates to rebuilding all of its indexes (see [Indexes](06-indexes.md)).

## Table optimization

```php
$db->table('products')->optimizeTable();
```

A heavy-weight maintenance pass:

1. read all records,
2. normalize each one against the schema (key order, drop unknowns, typed defaults for missing columns — `''`/`0`/`0.0`/`false`, `null` for nullable),
3. sort records by `id` ASC,
4. rewrite the data file,
5. rebuild every index from the rewritten data,
6. update `meta.lineCount` to the actual row count.

Use it occasionally — after long delete-heavy sessions, or right before a backup, to keep the on-disk layout tidy. The provider's normal runtime does not require it.

When the file holds unparseable NDJSON lines, `optimizeTable()` refuses to rewrite it (the rewrite would silently destroy them): resolve the lines `validate()` reports as `broken_record` findings first — see [Integrity](14-integrity.md).
