# Indexes

Indexes accelerate ordering and filtering without scanning the full table. They are NDJSON files of `{key, line}` pairs, sorted by key; lookups over the sorted keys are binary searches (O(log n + k)).

## Key format (v2) and the `indexFormat` marker

A key is the hex-encoded concatenation of parts, one per index field. A part = type tag + payload: `null`, `false`, `true` are single-byte tags; numbers share one tag and 16 sortable bytes (an int and a float of the same value produce **one** key: `key(99) === key(99.0)`, `-0.0` folds into `0.0`; beyond double precision, `|v| > 2^53`, neighboring ints differ in the residual bytes, and numeric range bounds compare without the residual — the index yields a superset, the shared post-filter trims it exactly, the result matches a full scan); strings are tag + escaped content + terminator, with **no truncation**. Parts are prefix-free, so a composite key is injective: component boundaries never blend. A `DESC` direction inverts the part's bytes — key order tracks value order both ways. `NAN`/`INF` are not indexable (`IndexKeyNonFinite`); a non-finite value in a query condition degrades to a full scan.

The format is stored per table in `meta.json` (`indexFormat`; a missing field reads as format 1). Indexes of a table below the current format are not used: such a table is read via full scans, and the first write (or `rebuildAllIndexes()`, `repairTable()`, `optimizeTable()`) rebuilds every index with the current encoder and stamps `indexFormat: 2`. `rebuildIndex()` of a single index on a format-1 table rebuilds all of them: one format-2 file under a format-1 marker would poison the table. `restore()` rebuilds indexes and stamps the format after restoring.

## Trust and degradation

Before using an index the reader checks an O(1) gate: the committed `byteSize` in meta matches the actual data file size and `indexFormat >= 2`. Any doubt — a foreign append, lost meta, a format below the current one — **silently** degrades the query to a full scan (the result stays correct, no exception); the next write under the table EX lock heals and stamps.

A trusted (v2) index is read with structural validation: the entry count equals `lineCount`, the lines form a permutation with no duplicates or gaps, every key is syntactically well-formed. A violation is a **loud** `JsonProviderServiceException`: a structurally corrupt index never silently serves wrong rows. An empty index result is authoritative only after that validation. The same checks (category `JsonProviderServiceException`) run in `validateTable()`, and `repairTable()` rebuilds and stamps.

## How an index is chosen for a query

`select` picks one index per call, by this priority:

1. **Ordering index** — if `orderBy()` matches the index fields and directions exactly. Records are read in index order, no in-memory sort.
2. **Filter index** — if the first index field matches the field of the first applicable filter condition. The index narrows the rows actually read from the data file.
3. **Full scan** — if no index applies.

The PK index is always present and is the natural pick for `WHERE id = N`.

With distinct fields the index provides ordering and filtering only: `limit`/`offset` are applied after deduplication, never inside the index path.

In the `Locale` comparison mode (see [Query builder](08-query-builder.md)) indexes are not used for ordering or ranges over string columns — the byte-ordered index disagrees with the collator; string `=`/`IN` stay indexable.

## Operators that can use an index

Only conditions on the **first** field run through a composite index; a condition on a later field is not served by the index (post-filtering or a full scan yields the correct result). A select without `orderBy` returns rows in file order whether or not an index was used.

Indexes are added and dropped on a live table via `addIndex()`/`dropIndex()` — the file is built from current data before the schema is published; see [Schema mutations](12-schema-mutations.md). Names with the `_fk_` prefix are reserved for engine-managed FK backing indexes.

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

- Index name `pk` is reserved for the primary key index. Declaring a user index with this name (without `isPrimary=true`) raises `JsonProviderSchemaException`.
- The `_fk_` prefix is reserved for engine-managed FK backing indexes: `addIndex('_fk_...')` raises `ReservedIndexName`.
- Index file name is **not** stored in the schema — it is derived from the index name (`<index-name>.index.ndjson`). You read it via `IndexSchema::getFileName()` if you need the path.

## Service FK (backing) indexes

A relation with a probing action (`cascade`/`restrict` on delete or update) must have a **backing index** — a single-column index on the child's FK column the engine uses for existence probes. The relation API (`addRelation`) provisions it:

- if the child already has a single-column **user** index on the FK column, it is reused (`RelationSchema::backingIndex` = its name);
- otherwise a **service** index `_fk_<column>` is built (`isService: true` in the schema). Several relations on the same column share one service index.

Service index properties:

- maintained by the regular index write machinery (append/rebuild/repair) like any index;
- **invisible to query planning**: a select over the FK column behaves as if the column were unindexed — the service index exists for FK probes only;
- their lifecycle belongs to the relation API: `dropIndex` of a service name raises `ReservedIndexName`; `dropRelation` removes the service index unless another probing relation still uses it (a reused user backing is never touched);
- `dropIndex` of a **user** index serving as a backing does not strand the FK: a service replacement is built in the same schema change and the relation is re-pointed to it.

FK probes pass the same trust pipeline as the select path: the O(1) byteSize+format gate (a stale index degrades to an honest scan of the already-read rows), then full structural validation (corruption raises `JsonProviderServiceException`). A restrict probe with a missing/non-covering backing is a loud configuration error, `FkBackingIndexMissing`, never a silent scan: for existing databases the first `repair()` closes it (reuses a covering user index or provisions `_fk_<column>`, and sets `backingIndex` — structural repair, data untouched).

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
