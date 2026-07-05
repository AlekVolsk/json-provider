# Indexes

Indexes accelerate ordering and filtering without scanning the full table. They are NDJSON files of `{key, line}` pairs, sorted by key.

## How an index is chosen for a query

`select` picks one index per call, by this priority:

1. **Ordering index** — if `orderBy()` matches the index fields and directions exactly. Records are read in index order, no in-memory sort.
2. **Filter index** — if the first index field matches the field of the first applicable filter condition. The index narrows the rows actually read from the data file.
3. **Full scan** — if no index applies.

The PK index is always present and is the natural pick for `WHERE id = N`.

## Operators that can use an index

```text
=   ✓  exact key
>   ✓  range from boundary up
>=  ✓
<   ✓  range up to boundary
<=  ✓
BETWEEN ✓
IN  ✓
LIKE ✗  always full scan
NOT *   ✗  always full scan
```

## Naming and reserved names

- Index name `pk` is reserved for the primary key index. Declaring a user index with this name (without `isPrimary=true`) raises `PK_CONTRACT_VIOLATED`.
- Index file name is **not** stored in the schema — it is derived from the index name (`<index-name>.index.ndjson`). You read it via `IndexSchema::getFileName()` if you need the path.

## Adding indexes at table creation

```php
$db->createTable(TableSchema::create(
    name: 'events',
    columns: ['userId' => 'int', 'createdAt' => 'string'],
    indexes: [
        new IndexSchema(
            name: 'idx_events_user_created',
            fields: [
                new IndexFieldSchema('userId',    SortDirectionEnum::ASC),
                new IndexFieldSchema('createdAt', SortDirectionEnum::DESC),
            ],
        ),
    ],
));
```

A composite index covers an `orderBy` only when **all** fields and directions match exactly.
