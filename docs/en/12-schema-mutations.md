# Schema mutations — reorderColumns

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
