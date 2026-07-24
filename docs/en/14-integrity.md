# Integrity — validate and repair

The provider ships with a built-in integrity service. `validate*` is read-only; `repair*` mutates.

## Validate

```php
$report = $db->validateTable('products');
$report = $db->validate();   // whole DB

if ($report->hasErrors()) {
    error_log($report->format());
}
```

`validate()` checks every table and the database root. What is reported:

| Category | Severity | Meaning |
| - | - | - |
| `table_file_missing` | error | data file for a declared table is missing |
| `index_file_missing` | error | index file declared in schema is missing |
| `index_file_corrupt` | error | index file cannot be read |
| `index_drift` | error | index does not match data (count/keys/dangling line refs) |
| `orphan_index_file` | warning | file in the table subdir is not declared in the schema |
| `record_key_order` | warning | one or more records have key order or set differing from schema |
| `meta_entry_missing` | error | meta.json has no entry for a declared table |
| `meta_line_count_drift` | error | meta.lineCount disagrees with the actual row count |
| `meta_last_id_drift` | error | meta.lastInsertedId is below `max(id)` of existing records |
| `meta_orphan_entry` | warning | meta.json has an entry for a table not in the schema |
| `orphan_db_entry` | warning | DB root has a file or directory not part of the schema |
| `pk_duplicate` | error | one id is stored in two or more records (id and lines in the context) |
| `rename_incomplete` | error | a `_pendingRename` marker is left in meta.json — `renameTable` did not complete |
| `fk_backing_index_missing` | warning | a cascade/restrict relation has no covering backing index on the child FK column (legacy schema) |
| `fk_backing_index_orphaned` | warning | a service `_fk_` index is not the backing of any probing relation (dead weight) |
| `table_optimized` | info | table was sorted by id and reindexed (only emitted by repair) |
| `repair_failed` | error | a repair attempt threw |

The validator does **not** flag "records not sorted by id" — that is a repair preference, not a contract. Use `optimizeTable()` if you want a sorted on-disk layout.

## Repair

```php
$report = $db->repairTable('products');
$report = $db->repair();             // whole DB
```

`repair*` runs the validator, attempts to fix every actionable issue, and finishes each touched table with an `optimizeTable` pass. The repairer **never throws past its boundary** — failures are recorded inside the report's issues:

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
| `index_file_missing` | recreate file, rebuild from data |
| `index_file_corrupt` | rebuild from data |
| `index_drift` | rebuild from data |
| `orphan_index_file` | delete the file |
| `record_key_order` | rewrite records via the canonical key order, reindex |
| `meta_entry_missing` | re-init entry, derive lineCount and lastInsertedId |
| `meta_line_count_drift` | set to actual row count |
| `meta_last_id_drift` | set to `max(id)` of existing records |
| `meta_orphan_entry` | drop entry from meta |
| `orphan_db_entry` | delete file; a directory is removed as a whole only when every file in it is empty — a non-empty orphan requires a manual decision |
| `table_file_missing` | provision an empty data file, missing index files and the meta entry (the `createTable` crash window); lost data is not invented |
| `pk_duplicate` | **not repaired**: marked with `repairError` — duplicate PKs are resolved manually |
| `rename_incomplete` | roll the `renameTable` forward/back by the actual schema state (see below) |
| `fk_backing_index_missing` | reuse a covering single-column user index, or build the service index `_fk_<column>` from current data, and set the relation's `backingIndex` (structural repair, data untouched) |
| `fk_backing_index_orphaned` | remove the service index from the schema along with its file |

The `rename_incomplete` reconciliation runs **before** per-issue repair (otherwise a half-renamed table would be "repaired" as `table_file_missing` with fresh empty files under the new name): if the schema already holds the new name, meta and the filesystem are rolled forward under it (the directory and data-file renames are idempotent); if the schema still holds the old name, the rename never committed, the filesystem was never touched and only the marker is dropped. Only the database-level `repair()` reconciles: a single-table `repairTable()` holds the lock of one name only and honestly refuses (`repairError`) — as does any write into an affected table (a `RENAME_INCOMPLETE` exception) while the marker is alive. The `orphan_index_file` repair deletes only files shaped `*.index.ndjson` or empty ones: a non-empty file under any other name may be stranded data of a crashed rename and requires a manual (or reconcile) decision.

A property of the read layer worth knowing: an NDJSON line that `json_decode` cannot parse (broken JSON, invalid UTF-8) is silently skipped on read. The validator only sees this as `meta_line_count_drift`, and its repair commits the new count — legitimizing the loss. If per-row integrity is critical, compare the meta `lineCount` against the physical line count externally before repairing.

When records are normalized (`record_key_order`, `optimizeTable`), columns missing from a record are back-filled with the type default (`''`/`0`/`0.0`/`false`, `null` for nullable) — the same value `migrateColumns` would have written, so repairing a crashed migration converges to its target state instead of planting `null` into a not-null column. A present `null` stays `null`.

## Report rendering

```php
echo $report->format();
```

Plain-text, English, fixed-width. One line per issue with severity tag, category, table context and repair status.

```php
$report->hasErrors();
$report->hasWarnings();
$report->issuesBySeverity(IssueSeverity::ERROR);
$report->issuesByCategory(IssueCategory::META_LINE_COUNT_DRIFT);
```
