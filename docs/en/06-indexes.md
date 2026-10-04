# Indexes

Indexes accelerate ordering and filtering without scanning the full table. They are NDJSON files of `{key, line}` pairs, sorted by key; lookups over the sorted keys are binary searches (O(log n + k)).

## Key format (v2) and the `indexFormat` marker

A key is the hex-encoded concatenation of parts, one per index field. A part = type tag + payload: `null`, `false`, `true` are single-byte tags; numbers share one tag and 16 sortable bytes (an int and a float of the same value produce **one** key: `key(99) === key(99.0)`, `-0.0` folds into `0.0`; beyond double precision, `|v| > 2^53`, neighboring ints differ in the residual bytes, and numeric range bounds compare without the residual — the index yields a superset, the shared post-filter trims it exactly, the result matches a full scan); strings are tag + escaped content + terminator, with **no truncation**. Parts are prefix-free, so a composite key is injective: component boundaries never blend. A `DESC` direction inverts the part's bytes — key order tracks value order both ways. `NAN`/`INF` are not indexable (`IndexKeyNonFinite`); a non-finite value in a query condition degrades to a full scan.

The format is stored per table in `meta.json` (`indexFormat`; a missing field reads as format 1). Indexes of a table below the current format are not used: such a table is read via full scans, and the first write (or `rebuildAllIndexes()`, `repairTable()`, `optimizeTable()`) rebuilds every index with the current encoder and stamps `indexFormat: 2`. `rebuildIndex()` of a single index on a format-1 table rebuilds all of them: one format-2 file under a format-1 marker would poison the table. `restore()` rebuilds indexes and stamps the format after restoring.

## Trust and degradation

Before using an index the reader checks an O(1) gate: the committed `byteSize` in meta matches the actual data file size and `indexFormat >= 2`. Any doubt — a foreign append, lost meta, a format below the current one — **silently** degrades the query to a full scan (the result stays correct, no exception); the next write under the table EX lock heals and stamps.

## How a lookup runs

After a rebuild an index file is sorted by key as a whole; appends put new entries into an unsorted tail. A generation-2 database keeps derived files next to it (see [storage format](23-storage-format.md#derived-files)): the boundary of the sorted head of each index file and the offsets of the data file's lines. With them a lookup does not read the index file whole:

- the head — a binary search right over the file's text: a step is a seek into the middle of the range and one line, about log2(n) lines per bound, plus the lines of the range found;
- the tail is read whole and searched in memory; once the tail holds more than 1024 entries, the next insert rewrites the index file sorted;
- the data lines found are read by seeking to their offsets while at most one line in eight of the table is wanted, and in one pass over the file otherwise.

A lookup of one row in a table of 100k rows costs a fraction of a millisecond and barely depends on the table size.

The derived files carry the inode, size and content mark of the file they describe. When these do not match — another version rewrote the file, a process died between writing the data and the update, the file was replaced — the lookup reads and checks the index file whole. A stale derived file never yields wrong rows. Ordering by an index without conditions and FK probes always read the index whole.

## Checks during a lookup

An index read whole is checked whole: the entry count equals `lineCount`, the lines form a permutation with no duplicates or gaps, every key is syntactically well-formed.

A search over the head checks what it reads: every entry read is a `{key, line}` pair with a well-formed key and a line number inside the data file; the keys on the search path come in order; head and tail together hold exactly `lineCount` entries; no data line is found twice; the key built from every row returned equals the key of the index entry that pointed at it. Damage off the search path — a broken entry far from the key looked for — is invisible to such a search; `validate()` and `validateTable()`, which read the index file whole, find it.

Any violation is a **loud** `JsonProviderServiceException`: a structurally corrupt index never silently serves wrong rows. The same full checks run in `validateTable()`, and `repairTable()` rebuilds and stamps.

## How an index is chosen for a query

`select` picks one index per call, by this priority:

1. **Ordering index** — if `orderBy()` matches the index fields and directions exactly. Records are read in index order, no in-memory sort.
2. **Filter index.** When `=` conditions fix leading fields of an index in a row and, together with a range (`>`, `>=`, `<`, `<=`, `BETWEEN`) on the next field, cover two fields or more, the index covered on the most fields this way is taken, the one declared first on a tie. Otherwise, the first index whose first field matches the field of the first applicable filter condition. The index narrows the rows actually read from the data file.
3. **Full scan** — if no index applies.

The PK index is always present and is the natural pick for `WHERE id = N`.

With distinct fields the index provides ordering and filtering only: `limit`/`offset` are applied after deduplication, never inside the index path.

In the `Locale` comparison mode (see [Query builder](08-query-builder.md)) indexes are not used for ordering or ranges over string columns — the byte-ordered index disagrees with the collator; string `=`/`IN` stay indexable.

## Unique check on insert

`insert` checks a unique constraint through an index when the table has a user index on exactly the constraint's fields — in any order and with any directions: an equal full key means every field is equal. The link is computed from the fields and stored nowhere. Service `_fk_` indexes are not considered: the engine creates and drops them with relations. Indexes are never created for constraints automatically.

The lookup searches the sorted head and the tail, as a query does, with the same checks (see "Checks during a lookup"). The records found are compared by the constraint's type-strict key (see [unique constraint semantics](04-schema-model.md#unique-constraint-semantics)): the index key merges `1` and `1.0`, so it can only widen the candidates, never narrow them. A value present in the data but missing from the index through damage off the lookup path is invisible to this check — as it is to a query; a duplicate let through by such damage shows up in `validate()` as `unique_duplicate`, next to the finding about the damaged index.

Without a matching index, or when the head of the index file is not recorded (a generation-1 database, a stale derived file), `insert` reads the whole table, once for all such constraints. A record with `null` in any constraint field does not take part in the check and reads nothing. `update` and `importRecords` rewrite the table and check uniqueness over the whole resulting set — they need no index.

Without an index the cost of an insert grows with the table: the whole table is read and decoded. With an index on the same fields an insert costs almost the same as one into a table without the constraint. The index itself costs an append with `fsync` to its file on every insert and a rebuild on rewrites, so on a small table the full read may come out cheaper. `validate()` reports a constraint without an index as an `info` finding `unique_index_missing`.

## Operators that can use an index

A composite index searches by a key prefix: the `=` values of its leading fields in a row and, when present, a range on the next field — one run of the index file. Conditions on fields past the prefix, `IN` and `LIKE` included, are not served by the index — the post-filter checks them; `IN` works on the first field only. A select without `orderBy` returns rows in file order whether or not an index was used.

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

A relation with a probing action (`cascade`/`restrict` on delete or update) must have a **backing index** — an index whose first field is the child's FK column; the engine uses it for existence probes. A probe compares the first key part only, so the other fields of the index do not affect the answer. The relation API (`addRelation`) provisions it:

- if the child already has a single-column **user** index on the FK column, it is reused (`RelationSchema::backingIndex` = its name);
- with `setFkBackingPolicy(FkBackingPolicyEnum::LeadingColumn)` a user index whose first field is the FK column is reused as well (a single-column one still comes first). The table keeps one index less, and every insert appends to one file less; an FK probe reads the index file whole and costs slightly more on a composite index. By default (`SingleColumn`) only a single-column index is taken. Versions before 1.2 count such a relation as uncovered — before rolling back see [Versioned migrations](07-migrations.md#11--12);
- otherwise a **service** index `_fk_<column>` is built (`isService: true` in the schema). Several relations on the same column share one service index.

Service index properties:

- maintained by the regular index write machinery (append/rebuild/repair) like any index;
- **used by query planning** like user indexes: a select over the FK column goes through the service index. The index lives as long as its relation: after `dropRelation` (unless another probing relation still uses it) selects over the column silently fall back to a full scan. For a column queried regardless of the relation, declare a user index before adding the relation — `addRelation` reuses it as the backing;
- their lifecycle belongs to the relation API: `dropIndex` of a service name raises `ReservedIndexName`; `dropRelation` removes the service index unless another probing relation still uses it (a reused user backing is never touched);
- `dropIndex` of a **user** index serving as a backing does not strand the FK: the same schema change re-points the relation to a replacement — under `LeadingColumn` to another fitting user index when there is one, otherwise to a newly built service index.

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
