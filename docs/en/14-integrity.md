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
| `orphan_db_entry` | delete file, or remove subdirectory if empty |
| `table_file_missing` | not auto-repairable (data loss); reported as failure |

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
