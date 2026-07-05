# Index and table maintenance

## Index maintenance

Two operations let you preventively rebuild indexes without going through delete-and-recreate:

```php
$db->table('products')->rebuildIndex('idx_category');
$db->table('products')->rebuildAllIndexes();   // including PK
```

Both are atomic at the file level (each index file is replaced under an exclusive lock). Use them when you know an index is suspect — for example, after manual file tampering, or as a periodic maintenance task.

`rebuildIndex()` raises `INDEX_NOT_FOUND` if the named index does not exist in the table schema.

## Table optimization

```php
$db->table('products')->optimizeTable();
```

A heavy-weight maintenance pass:

1. read all records,
2. normalize each one against the schema (key order, drop unknowns, null-fill missing),
3. sort records by `id` ASC,
4. rewrite the data file,
5. rebuild every index from the rewritten data,
6. update `meta.lineCount` to the actual row count.

Use it occasionally — after long delete-heavy sessions, or right before a backup, to keep the on-disk layout tidy. The provider's normal runtime does not require it.
