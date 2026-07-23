# Indexes

Indexes accelerate ordering and filtering without scanning the full table. They are NDJSON files of `{key, line}` pairs, sorted by key; lookups over the sorted keys are binary searches (O(log n + k)).

## Key format (v2) and the `indexFormat` marker

A key is the hex-encoded concatenation of parts, one per index field. A part = type tag + payload: `null`, `false`, `true` are single-byte tags; numbers share one tag and 16 sortable bytes (an int and a float of the same value produce **one** key: `key(99) === key(99.0)`, `-0.0` folds into `0.0`; beyond double precision, `|v| > 2^53`, neighboring ints differ in the residual bytes, and numeric range bounds compare without the residual — the index yields a superset, the shared post-filter trims it exactly, the result matches a full scan); strings are tag + escaped content + terminator, with **no truncation**. Parts are prefix-free, so a composite key is injective: component boundaries never blend. A `DESC` direction inverts the part's bytes — key order tracks value order both ways. `NAN`/`INF` are not indexable (`INDEX_KEY_NON_FINITE`); a non-finite value in a query condition degrades to a full scan.

The format is stored per table in `meta.json` (`indexFormat`; a missing field reads as 1, legacy). The upgrade is lazy: tables with the old format are read via full scans, and the first write (or `rebuildAllIndexes()`, `repairTable()`, `optimizeTable()`) rebuilds every index with the current encoder and stamps `indexFormat: 2`. `rebuildIndex()` of a single index on a v1 table escalates to rebuilding all of them (one v2 file under a v1 marker would poison the table). `restore()` rebuilds indexes and stamps the format after restoring.

## Trust and degradation

Before using an index the reader checks an O(1) gate: the committed `byteSize` in meta matches the actual data file size and `indexFormat >= 2`. Any doubt — a foreign append, lost meta, legacy format — **silently** degrades the query to a full scan (the result stays correct, no exception); the next write under the table EX lock heals and stamps.

A trusted (v2) index is read with structural validation: the entry count equals `lineCount`, the lines form a permutation with no duplicates or gaps, every key is syntactically well-formed. A violation is a **loud** `INDEX_UNRELIABLE`: a structurally corrupt index never silently serves wrong rows. An empty index result is authoritative only after that validation. The same checks (category `INDEX_UNRELIABLE`) run in `validateTable()`, and `repairTable()` rebuilds and stamps.

## How an index is chosen for a query

`select` picks one index per call, by this priority:

1. **Ordering index** — if `orderBy()` matches the index fields and directions exactly. Records are read in index order, no in-memory sort.
2. **Filter index** — if the first index field matches the field of the first applicable filter condition. The index narrows the rows actually read from the data file.
3. **Full scan** — if no index applies.

The PK index is always present and is the natural pick for `WHERE id = N`.

With distinct fields the index provides ordering and filtering only: `limit`/`offset` are applied after deduplication, never inside the index path.

In the `Locale` comparison mode (see [Query builder](08-query-builder.md)) indexes are not used for ordering or ranges over string columns — the byte-ordered index disagrees with the collator; string `=`/`IN` stay indexable.

## Operators that can use an index

Only conditions on the **first** field run through a composite index; a condition on a later field is not served by the index (post-filtering or a full scan yields the correct result). Columns of unknown (passthrough) types are index-served for `=`/`IN` only — their cross-type comparison order does not match the key order, so ranges and ordering over them run as full scans. A select without `orderBy` returns rows in file order whether or not an index was used.

```text
=   ✓  exact key
>   ✓  range from boundary up
>=  ✓
<   ✓  range up to boundary
<=  ✓
BETWEEN ✓  (null or non-scalar bounds → full scan)
IN  ✓  (an empty list is an authoritative zero rows)
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
