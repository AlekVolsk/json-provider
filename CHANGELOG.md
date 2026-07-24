# Changelog

The package version stays 1.0.0 across the audit-fix rollout; production
databases are updated manually in place. This file records behavioral and
API changes an integrator must know when updating.

## Unreleased

### Foreign keys and cascades

- **Canonical FK direction.** The engine now resolves the child side (the
  table physically holding the FK column) by the relation type: belongsTo
  — the from table, hasMany/hasOne — the to table. Previously the from
  table was treated as the child for ANY type, so hasMany/hasOne edges
  were mis-wired. **Review every legacy hasMany/hasOne before
  upgrading**: an edge declared engine-style (from = child) now fails
  loudly with `RELATION_COLUMN_NOT_FOUND`, while an edge declared
  intuitively (the FK column really lives in the to table) was a no-op
  for years and now SILENTLY ACTIVATES its onDelete/onUpdate actions —
  including cascade deletion of children.
- **Relation DDL API.** New `addRelation()` / `dropRelation()` /
  `relations()`. addRelation validates the declaration (tables and
  columns exist — `RELATION_COLUMN_NOT_FOUND`; base types match, `|null`
  ignored — `RELATION_TYPE_MISMATCH`; the referenced column is the PK or
  single-column-unique — `RELATION_REFERENCES_NOT_UNIQUE`; onUpdate is
  undeclarable on the PK — `RELATION_ON_UPDATE_ON_PK`; SET NULL requires
  a nullable FK — `FOREIGN_KEY_SET_NULL_NOT_NULLABLE`) and rejects a
  duplicate of the canonical edge in either notation
  (`RELATION_ALREADY_EXISTS`). Hand-editing information_schema.json
  remains unsupported; relation entries now also validate table/column
  names against the identifier whitelist and a non-string backingIndex
  is rejected on load.
- **Cascade engine.** The multi-table effect of delete/update is planned
  entirely before the first write: one disk read per affected table,
  cyclic (A<->B) and self-referential cascades terminate and delete
  their full closure (previously transitive descendants could survive),
  onUpdate cascades propagate transitively, and every validation —
  executable-edge schema checks (dead noAction edges stay exempt), SET
  NULL nullability, cascade patch typing through the insert/update
  codec, unique checks against the FINAL batch state (inter-target
  duplicates included) — aborts with the disk untouched. The apply
  phase is two-phase: PREPARE fsyncs every table to a temp sibling (a
  failing table aborts the whole set), COMMIT renames children before
  parents, so a mid-commit crash cannot orphan children invisibly and
  re-running the statement converges. A batch update that previously
  committed row-by-row (partial state on a later-row unique failure) is
  now all-or-nothing.
- **Restrict is MySQL-immediate.** The probe runs against the ORIGINAL
  child state: a violation counts even when the referencing child row is
  deleted by the same statement. A self-referential restrict table can
  no longer be emptied by one delete-all — delete leaves before roots.
- **FK backing indexes.** Relations with probing actions
  (cascade/restrict on delete or update) carry a backing index on the
  child FK column: addRelation reuses a single-column user index or
  builds a service `_fk_<column>` one (`isService`, invisible to query
  planning, undropable via dropIndex — `RESERVED_INDEX_NAME`).
  dropRelation removes an unshared service backing; dropIndex of a user
  backing re-points the relation to a freshly built service replacement.
  Probes ride the same trust pipeline as selects (stale index — honest
  scan; structural corruption — `INDEX_UNRELIABLE`). A restrict probe
  with no covering backing is a configuration error
  (`FK_BACKING_INDEX_MISSING`); `validate()` reports it
  (`fk_backing_index_missing`, warning) and `repair()` provisions the
  service index — run repair once after upgrading a database with
  cascade/restrict relations.
- Type-skewed executable edges now fail the statement loudly
  (`RELATION_TYPE_MISMATCH`) instead of silently matching nothing; a
  cascaded temporal value keeps its stored UTC form (never re-encoded).
- The mutation lock plan is re-derived inside the critical section and
  the statement retries when a concurrently added relation made the
  pre-lock plan stale — a cascade can never reach a table the lock frame
  does not hold.
- A SET_NULL on delete is a value change the grandchildren's onUpdate
  edges observe: a restrict grandchild now blocks the delete (previously
  the declared restrict was silently bypassed) and cascade/setNull
  grandchildren follow the null instead of dangling.
- DDL guards around live relations: `dropUniqueConstraint` refuses to
  drop the uniqueness ground of a relation's referenced column
  (`RELATION_REFERENCES_NOT_UNIQUE`), `migrateColumns` refuses to drop a
  column that is a side of a declared relation
  (`MIGRATE_FIELD_UNKNOWN_COLUMN` with a drop-the-relation-first hint) —
  both previously let a schema change corrupt FK semantics (a cascade
  deleting the children of a living duplicate parent; every parent
  delete failing on a vanished FK column).
- `renameColumn` renames a service backing index with its column
  (`_fk_<old>` → `_fk_<new>`, relations re-pointed); `dropTable` of a
  parent releases the service backings its relations provisioned in the
  child tables; a service index orphaned any other way is reported as
  `fk_backing_index_orphaned` and removed by `repair()` (it is
  invisible to `dropIndex` by design, so repair is the only exit).
- The per-process cache of every table in an FK write set is dropped
  before the files flip, so a failure in the commit tail can no longer
  leave the writing process serving pre-plan rows over already-replaced
  files.

### DDL, schema and identifiers

- Table, column and index names are validated against a whitelist
  (`[A-Za-z0-9_][A-Za-z0-9_-]{0,63}`, no dots or path separators):
  `INVALID_TABLE_NAME` / `INVALID_COLUMN_NAME` / `INVALID_INDEX_NAME`.
  The check guards the schema boundary, so a database whose
  information_schema.json holds names outside the whitelist will not load
  — rename the directory, the `<name>.ndjson` file and the schema/meta
  keys before upgrading. The `_fk_` index-name prefix and the
  `_pendingRename` table name are reserved. `dropTable` with an invalid
  name now throws (previously a silent no-op); the storage layers carry
  an explicit anti-traversal guard (`.`/`..` previously slipped through
  a pure basename comparison).
- Column types are a closed set: 12 base types plus their `|null`
  variants (`ColumnTypes::all()`). Anything else — `'integer'`,
  `'datetime|nullable'`, a hand-edited typo, a passthrough type like
  `'variant'` — is rejected with `INVALID_COLUMN_TYPE` on DDL and on
  schema load alike. Databases relying on unknown ("passthrough") column
  types must migrate them to declared types before upgrading.
- information_schema.json is parsed strictly: a broken relation entry
  (missing/non-string keys, unknown type) raises
  `RELATION_ENTRY_INVALID`; a present onDelete/onUpdate outside
  noAction/cascade/setNull/restrict raises `RELATION_ACTION_INVALID`
  (previously `'CASCADE'` silently became noAction and the FK was
  disabled); broken index/unique/table entries raise `INVALID_SCHEMA`
  with the exact address instead of being silently skipped.
- `migrateColumns` is now about columns ONLY: indexes, unique
  constraints and comments carried by the target schema are ignored and
  the table keeps its own. Index/constraint structure changes go through
  the new `addIndex()` / `dropIndex()` / `addUniqueConstraint()` /
  `dropUniqueConstraint()` API (`addUniqueConstraint` pre-checks stored
  data and rejects duplicates with nothing written). Dropping a column
  still referenced by an index or constraint now requires dropping that
  structure first.
- `migrateColumns`/`reorderColumns`/`renameColumn` encode-probe the full
  record set before touching the schema or any file, so an unencodable
  stored value aborts with the disk untouched; repair converges a crash
  window between schema and data to the migration target (added not-null
  columns back-filled with type defaults, not null).
- New `truncate()` (SQL semantics: data gone, indexes rebuilt empty,
  auto-increment reset to 0), `renameColumn()` (schema, data, indexes,
  constraints, comments, relation sides and the DTO binding all follow;
  the crash window heals to the new schema with defaults — back up
  first) and `renameTable()` (schema key, relations, meta entry and the
  physical directory/file move; crash-protected by a `_pendingRename`
  marker in meta.json that `repair()` reconciles deterministically by
  the actual schema state).
- Insert guards the id sequence: if the meta counter fell behind the
  data (restored meta.json), the watermark is re-derived from the data
  before allocation instead of minting a duplicate id. Stored duplicate
  PKs are reported by `validate()` as `pk_duplicate` (error) and are
  deliberately not auto-repaired.
- Reading a data file whose first record carries an invalid column key
  now fails loudly (`INVALID_COLUMN_NAME`) — a cheap tripwire against
  foreign tampering.
- The renameTable crash window is fenced end to end: while a
  `_pendingRename` marker is alive, writes into an affected table and a
  further `renameTable` raise `RENAME_INCOMPLETE`, and the single-table
  `repairTable()` refuses to touch it (only the database-level
  `repair()` reconciles — it also runs the reconciliation before
  per-issue repair). The orphan-file repair no longer deletes non-empty
  files that are not index files, so stranded data of a crashed rename
  can never be destroyed by repair.
- Purely numeric identifiers ('0', '42') are rejected — PHP casts such
  array keys to int and the engine would crash with a TypeError instead
  of a provider exception.
- Repair refuses to invent values it cannot know: a stored record
  missing a not-null temporal column is reported as a repair failure
  instead of being back-filled with null; `truncate` self-heals a
  missing meta entry before emptying the table.
- `FOREIGN_KEY_RESTRICT` gained localized messages (previously the
  message degraded to the bare key).

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
  string/temporal.
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
