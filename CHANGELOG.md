# Changelog

The package version stays 1.0.0 across the audit-fix rollout; production
databases are updated manually in place. This file records behavioral and
API changes an integrator must know when updating.

## Unreleased

### Index format v2

- Index keys use a new prefix-free typed encoding: numbers of equal value
  share one key (`key(99) === key(99.0)`; beyond `2^53` numeric range
  bounds select a superset trimmed by the exact post-filter), strings are
  not truncated, composite keys are injective, `DESC` ranges are mirrored
  correctly. The
  format is versioned per table via `indexFormat` in `meta.json`; tables
  without the marker are treated as legacy. No forced migration: legacy
  tables are read via full scans, the first write (or `rebuildAllIndexes()`,
  `repairTable()`, `optimizeTable()`, `restore()`) rebuilds the indexes and
  stamps the format. Roll back to an older package version only after
  running `rebuildAllIndexes()` with that older version.
- A structurally corrupt v2 index now raises `INDEX_UNRELIABLE` instead of
  silently serving (or dropping) rows; a merely stale index (foreign
  append, byteSize desync) silently degrades to a full scan. `validate()`
  reports these as `index_unreliable` (error) and `index_format_outdated`
  (info); `repair()` rebuilds and stamps.
- Index range/EQ/IN lookups are binary searches over the sorted entries.
- `IndexManager::searchLines()` signature changed (takes the table schema
  and pre-validated entries); `readIndexValidated()` and `eqExists()` are
  new. `rebuildIndex()` on a legacy-format table escalates to rebuilding
  all indexes of the table.

### Query validation

- `where` values are validated against the column types before the query
  executes: numeric strings and cross-type values raise
  `CONDITION_TYPE_MISMATCH` (previously loose comparison could match — or
  silently match nothing); the single coercion is an int condition on a
  float column; `NAN`/`INF` raise `NON_FINITE_FLOAT`; null range bounds
  are rejected. Temporal conditions must be system-format strings.
- Columns missing from the schema are rejected with
  `QUERY_UNKNOWN_COLUMN` in `where`, `orderBy`, `isDistinct` and
  `selectColumn` — previously a typo in a NOT-condition could match (and
  delete) every row.
- `BETWEEN` requires exactly `[min, max]` non-null scalars and `IN`
  requires an array (`CONDITION_MALFORMED`); an empty `IN` list matches
  nothing. A ghost row lacking a field is filtered as if the field were
  null.
- LIKE gains escaping: `\%` matches a literal percent, `\\` a literal
  backslash; `_` stays literal. Patterns must be strings and the column
  string/temporal/passthrough.
- `orderBy` directions are case-insensitive but strict
  (`INVALID_SORT_DIRECTION` instead of silently sorting ascending);
  negative `limit`/`offset` raise `INVALID_LIMIT`/`INVALID_OFFSET`.
- Distinct keys are type-distinguishing: `null`, `''`, `false`, `0`,
  `'0'`, `true`, `1`, `'1'` are eight distinct values (previously string
  casting collapsed them).
- An unknown operator string in `where()` raises `INVALID_OPERATOR`
  instead of a raw `ValueError`; a PCRE evaluation failure inside LIKE
  (catastrophic backtracking on adversarial patterns) raises
  `LIKE_EVALUATION_FAILED` instead of silently reading as "no match"
  (which inverted through NOT LIKE could delete protected rows).
- FK cascade conditions are engine-built from stored values and bypass
  the user-input validation: cascading over temporal FK columns now
  matches the stored UTC form under any PHP timezone (previously the
  value was shifted twice and children were silently orphaned), and a
  type-skewed FK schema no longer makes the parent row undeletable.
- `limit(0)->selectOne()` returns null (LIMIT 0 contract); unknown
  `orderBy` columns are rejected on `count()`/`exists()` too.

### Query semantics

- String ordering comparisons (`>`, `>=`, `<`, `<=`, `BETWEEN`, `ORDER BY`)
  are bytewise (strcmp) — previously PHP loose comparison could coerce
  numeric strings (`'9' < '10'`); now `'10' < '9'`, consistently across
  full scans, ORDER BY and indexes. Natural-language ordering is available
  via `setComparisonMode(ComparisonMode::Locale)` (ext-intl; order only,
  equality stays exact).
- With `isDistinct()`, `limit`/`offset` are never pushed into the index
  path: pagination applies after deduplication (previously a page could be
  sliced twice).

### Storage format

- Float columns now keep their zero fraction on disk: `99.0` is written as
  `"price":99.0` (`JSON_PRESERVE_ZERO_FRACTION` on append, full rewrite and
  the insert probe), so floats decode back as PHP floats. Legacy rows
  written as bare ints (`"price":99`) are widened to float on every read
  path; no migration is required. Optionally run `optimizeTable()` once per
  table to rewrite files in the new format. The sign of a legacy `-0` row
  is not restored (`-0` → int 0 → float 0.0); freshly written `-0.0` keeps
  its sign. Flush external caches (Redis/APCu/Memcached) on deploy: entries
  warmed by the old version hold unwidened ints.

### API

- `UniqueConstraint::keyOf()` signature changed from `string` to `?string`:
  it returns `null` when any constraint field is `null` or missing (the
  record does not participate in the constraint). New public
  `UniqueConstraint::keyPart()` canonicalizes a single unique-key value.

### Behavior

- Unique constraints follow SQL NULL semantics: any number of records with
  `null` in a constraint field coexist; previously `null` collided with
  `''` and `0` through string casting.
- Unique keys are type-strict: `1`, `1.0`, `'1'` and `true` are four
  distinct values and never conflict with each other. In a declared
  `float` column an int is widened to float on write and read, so `1` and
  `1.0` there remain one key.
- `NAN`, `INF` and `-INF` are rejected on write into float columns with
  `NON_FINITE_FLOAT` (previously they aborted later with a generic
  `INVALID_RECORD`).
- Strings that are not valid UTF-8 are rejected on write with
  `INVALID_UTF8`.
