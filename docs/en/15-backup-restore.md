# Backup and restore

The provider can export the current data state to a single `.tar.gz` archive and restore it back. No external tools or composer packages — uses the bundled `ext-phar` and `ext-zlib` extensions.

## Backup

```php
$archivePath = $db->backup('/var/backups');
$archivePath = $db->backup('/var/backups/snapshot.tar.gz');
// explicit path
```

Behaviour:

- when `$destination` is a directory, a timestamped file name is generated;
- when it lacks the `.tar.gz`/`.tar` extension, `.tar.gz` is appended;
- when the resolved path is already taken → `BackupArchiveExists`;
- when the resolved path lies inside the DB directory → `BackupDestinationInsideDb` (the backup would instantly become an orphan for the integrity validator);
- only the schema and table data are stored — meta and indexes are derived from data and rebuilt on restore.

The whole export runs **under the exclusive database lock**: no writer is in flight, every table comes from one committed generation — a cross-table cascade (`cascade`/`setNull`) can never be captured half-applied, and no FK can dangle between the archive's tables. The lock is held through the compression as well. Only schema-declared files enter the archive — temporary `*.tmp` files are never included.

Archive layout:

```text
manifest.json                 # {format, version, createdAt, tables, counters, checksums}
information_schema.json
tables/<table>.ndjson
```

The manifest carries two validation fields (the format version stays `1`, the fields are optional):

- `counters` — `table → lastInsertedId` at export time, taken from meta and **not** derived from data: a deleted high-water id is never re-minted after a restore;
- `checksums` — `archive member name → sha256` of its bytes, computed from the very bytes put into the archive. `manifest.json` itself is not covered: swapping the manifest **together** with the members it describes stays undetectable — when that matters, store the archives under an external integrity layer (signatures, immutable storage).

## Restore

```php
$db->restore('/var/backups/snapshot.tar.gz');
```

Behaviour:

1. archive validation: manifest format and version, then a **sha256 check of every member** the manifest knows a checksum for — any difference raises `BackupChecksumMismatch` and nothing touches the DB. A legacy archive (no `counters`/`checksums`) restores without verification;
2. the table set is matched against the current schema (in the default mode — see adopt below);
3. under db EX plus EX locks on every involved table: a safety snapshot of the current DB state is taken into a temporary archive (the nested export re-enters the held lock);
4. per table: records are read from the archive, normalized against the schema, sorted by `id`, the data file is written, indexes are rebuilt and stamped format v2, `lastInsertedId` is restored as `max(manifest counter, max(id))` — a legacy archive falls back to `max(id)` alone; then files the schema does not declare are swept from the table subdirectory (stray indexes of dropped indexes, abandoned `*.tmp`) — after a restore `validate()` is clean of `orphan_index_file`;
5. on success the safety snapshot is deleted;
6. on failure the DB is rolled back from the safety snapshot (schema, data and counters come from the snapshot, including the counters of **its** manifest) and `JsonProviderServiceException` is thrown. When the rollback fails too, both messages plus the snapshot path are present in the exception message so you can recover manually.

Restore serializes against every writer: concurrent inserts/updates either fully complete before the snapshot or start after the restore.

In the default mode the archive's table set must **exactly match** the schema — extra/missing tables → `JsonProviderServiceException`. Column sets inside the records do not have to match: missing columns are back-filled with the type's default (`''`/`0`/`0.0`/`false`, `null` for nullable; a missing not-null temporal column has no default and fails the restore loudly), extra keys are dropped. Unparseable archive lines are skipped with a `warning` to the PSR-3 logger (when one is attached).

## Restoring with the archived schema (adopt)

```php
$db->restore($archive, adoptArchivedSchema: true);
$db->restore($archive, adoptArchivedSchema: true, pruneExtraTables: true);
```

With `adoptArchivedSchema: true` the archived `information_schema.json` **replaces** the current schema — the path for restoring into an empty DB and for rolling back structural changes:

- the archived schema goes through the same strict loaders as the live one: table/column names (path-traversal protection — the name `..` is rejected), the closed type list, the strict relation parser. An invalid schema throws before any mutation; a swapped schema is caught even earlier by its checksum;
- archive tables absent from the current DB are materialized from scratch (data file, indexes, meta);
- current-DB tables absent from the archive **block the restore by default** (`JsonProviderServiceException` with a hint) — data is never dropped silently; with `pruneExtraTables: true` they are removed together with their files and meta;
- a failed restore rolls the schema back too: the rollback re-adopts the snapshot's schema.
