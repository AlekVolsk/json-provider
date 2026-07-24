# Integrity — validate and repair

The provider ships with a built-in integrity service. `validate*` is read-only; `repair*` mutates.

## Severity scale

One unified scale shared by the validator report and the optional PSR-3 logger (`IssueSeverity`):

| Level | Criterion | PSR-3 |
| - | - | - |
| `critical` | working with the database is impossible — reads or writes fail even at the full-scan level | `critical` |
| `error` | a schema-level structure is broken, but full-scan reads and writes still work | `error` |
| `warning` | relations or data typing do not match the schema | `warning` |
| `info` | a data-only observation with no schema involved (orphan FKs under `noAction`, successful optimization) | `info` |

`IssueSeverity::psrLevel()` returns the PSR-3 level, `rank()` the numeric rank used for sorting (critical=0 … info=3). The report (`IntegrityReport::$issues`) is ordered critical-first; emission order is kept within one level. `hasErrors()` is true for `error` **and** `critical`.

When a PSR-3 logger is attached to the instance (`JsonDataProvider::getInstance($path, $cache, $logger)`), every finding of a validation run is logged exactly once at its severity's level. Runtime degradations use the same scale: the silent fallback of index reads to full scans (byteSize desync, legacy format) is `info`, the `INDEX_UNRELIABLE` throw is `error`, skipped unparseable lines during restore are `warning`, a degraded cache backend is `warning` (the cache adapter's own logger, see [16-caching.md](16-caching.md)).

## Validate

```php
$report = $db->validateTable('products');
$report = $db->validate();   // whole DB
```

`validate()` checks every table and the DB root. What gets reported:

| Category | Severity | Meaning |
| - | - | - |
| `table_file_missing` | critical | the data file of a declared table is missing |
| `meta_entry_missing` | critical | meta.json has no entry for a declared table (insert is impossible) |
| `meta_entry_corrupt` | critical | the meta entry is corrupt (not an object, a counter is not an int) |
| `index_file_missing` | error | the file of a schema-declared index is missing |
| `index_file_corrupt` | error | an index file cannot be read |
| `index_drift` | error | an index disagrees with the data (count/keys/dangling line refs) |
| `index_unreliable` | error | structural corruption of a v2 index (permutation violation) |
| `meta_line_count_drift` | error | meta.lineCount differs from the actual row count |
| `meta_last_id_drift` | error | meta.lastInsertedId is below `max(id)` of the stored records |
| `pk_duplicate` | error | one id is stored in two or more records (id and lines in the context) |
| `rename_incomplete` | error | a `_pendingRename` marker is left in meta.json — `renameTable` did not complete |
| `fk_backing_index_missing` | error | a cascade/restrict relation has no covering backing index on the child FK column (legacy schema) |
| `repair_failed` | error | a repair attempt threw or refused |
| `broken_record` | warning | an unparseable NDJSON line (physical line number and raw text in the context); report-only |
| `present_null` | warning | an explicit `null` in a non-nullable column (column and line in the context); report-only |
| `unique_duplicate` | warning | stored data violates a declared unique constraint (constraint name and lines in the context); report-only |
| `fk_orphan` | warning / info | a child FK value with no matching parent key: `warning` under a declared enforcing action (cascade/restrict/setNull — the enforcement was bypassed), `info` under `noAction` (an accepted fact of the data); a relation addressing a missing table/column or joining differently-typed columns (reachable only through hand-edits) is reported too; report-only |
| `orphan_index_file` | warning | a file in the table subdirectory is not declared in the schema |
| `record_key_order` | warning | one or more records have key order/set differing from the schema |
| `meta_orphan_entry` | warning | meta.json holds an entry for a table outside the schema |
| `orphan_db_entry` | warning | a file or directory outside the schema sits in the DB root |
| `fk_backing_index_orphaned` | warning | a service `_fk_` index is not the backing of any probing relation (dead weight) |
| `index_format_outdated` | info | indexes use the pre-v2 format; reads degrade to full scans until the next write/repair |
| `table_optimized` | info | the table was sorted by id and reindexed (repair only) |

The `fk_orphan` checks are database-level (they need both sides' data) and run only in `validate()`; `unique_duplicate`, `present_null` and `broken_record` are per-table and visible to `validateTable()` as well. FK value comparison is type-strict — the same canonical `keyPart` encoding the unique checks and the cascade engine use.

The validator does **not** flag "records not sorted by id" — that is a repair preference, not a contract. Call `optimizeTable()` when you want the sorted layout.

## Repair

```php
$report = $db->repairTable('products');
$report = $db->repair();             // whole DB
```

`repair*` runs the validator, attempts to fix every actionable issue, and finishes every touched table with an `optimizeTable` pass. The repairer **never throws past its boundary** — failures are recorded inside the report's issue objects:

```php
foreach ($report->issuesByCategory(IssueCategory::INDEX_DRIFT) as $i) {
    if (!$i->repaired) {
        error_log('repair failed: ' . ($i->repairError ?? 'unknown'));
    }
}
```

What gets repaired:

| Issue | Action |
| - | - |
| `index_file_missing` | recreate the file, rebuild from data |
| `index_file_corrupt` | rebuild from data |
| `index_drift` | rebuild from data |
| `index_unreliable` | rebuild from data, stamp format v2 |
| `index_format_outdated` | rebuild every index of the table, stamp v2 |
| `orphan_index_file` | delete the file |
| `record_key_order` | rewrite records in canonical order, reindex (refuses on unparseable lines — see below) |
| `meta_entry_missing` | re-init the entry, derive lineCount/lastId |
| `meta_entry_corrupt` | drop the broken entry and re-init from data (like missing) |
| `meta_line_count_drift` | set the actual row count |
| `meta_last_id_drift` | set `max(id)` of the stored records |
| `meta_orphan_entry` | drop the entry from meta |
| `orphan_db_entry` | delete the file; a directory is removed as a whole only when every file in it is empty — a non-empty orphan needs a manual decision |
| `table_file_missing` | provision an empty data file, missing index files and the meta entry (the `createTable` crash window); lost data is not invented |
| `pk_duplicate` | **not repaired**: marked with `repairError` — duplicate PKs are resolved manually |
| `rename_incomplete` | roll the `renameTable` forward/back by the actual schema state (see below) |
| `fk_backing_index_missing` | reuse a covering single-column user index or build the service `_fk_<column>` from current data and point the relation's `backingIndex` at it (structural repair, data untouched) |
| `fk_backing_index_orphaned` | drop the service index from the schema and delete its file |
| `broken_record`, `present_null`, `fk_orphan`, `unique_duplicate` | **report-only, untouched**: repair fixes structures, not data — it never quarantines, rewrites or deletes user records to make a finding go away |

The `rename_incomplete` reconciliation runs **before** per-issue repair (otherwise the half-renamed table would be "healed" as `table_file_missing` with fresh empty files under the new name): when the schema already holds the new name, the meta entry and the filesystem are rolled forward under it (directory and data-file renames are idempotent); when the schema still holds the old name, the rename never committed, the filesystem was never touched and only the marker is dropped. Only the full `repair()` reconciles: a single-table `repairTable()` holds one name's lock and honestly refuses (`repairError`) — as does any write into an affected table (`RENAME_INCOMPLETE` exception) while the marker lives. The `orphan_index_file` repair deletes only `*.index.ndjson`-shaped or empty files: a non-empty file under any other name may be stranded data of a rename crash and needs a manual (or reconcile) decision.

### Protecting unparseable lines from loss

An NDJSON line `json_decode` cannot parse is silently skipped by the read layer — but the validator now surfaces every such line as a `broken_record` finding (physical line number and raw text in the `context`). Any full "read → write" rewrite of the file would destroy those lines, so `optimizeTable()` and the `record_key_order` repair **refuse** to rewrite a file holding any (`REPAIR_FAILED` / `repairError` with the line count): resolve them manually first — fix the line's text in the file or knowingly delete it.

When records are normalized (`record_key_order`, `optimizeTable`, restore), columns missing from a record are back-filled with the type's default (`''`/`0`/`0.0`/`false`, `null` for nullable) — the same value `migrateColumns` would have written, so repairing a crashed migration converges to the migration's target state instead of planting `null` into a not-null column. The regular write path (the full table rewrite of `update`) performs the same typed back-fill. A `null` PRESENT in a record stays `null` — and stays visible to the validator as `present_null` until the data is fixed.

## Rendering the report

```php
echo $report->format();
```

Plain text, English, fixed width. One line per issue with the severity tag, category, table context and repair status; lines go critical-first.

```php
$report->hasErrors();      // true for error AND critical
$report->hasWarnings();
$report->issuesBySeverity(IssueSeverity::CRITICAL);
$report->issuesByCategory(IssueCategory::META_LINE_COUNT_DRIFT);
```
