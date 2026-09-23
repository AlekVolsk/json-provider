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
- when the destination directory is missing or not writable → `BackupDestinationNotWritable`;
- when writing the archive fails (e.g. ext-phar rejects a path with `.phar` in a directory name) → `BackupWriteFailed` carrying the reason; the temporary files are removed, nothing is left at the destination, and a repeated backup to the same path is not blocked;
- when the resolved path lies inside the DB directory → `BackupDestinationInsideDb` (the backup would instantly become an orphan for the integrity validator);
- before the export the database is integrity-checked under the same lock: any finding of `error` severity or worse (missing data file, broken index, half-finished `renameTable`) → `BackupSourceInvalid`, no archive is created — run `validate()`/`repair()` first. `warning` findings (unique duplicates, FK orphans) do not block the export: the archive keeps the data as it is;
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

1. archive validation: manifest format and version, then a **sha256 check of every member** the manifest knows a checksum for — any difference raises `BackupChecksumMismatch` and nothing touches the DB. An archive without `counters`/`checksums` (the manifest fields are optional) restores without verification;
2. the table set is matched against the current schema (in the default mode — see adopt below);
3. under db EX plus EX locks on every involved table: a safety snapshot of the current DB state is taken into a temporary archive — `<temp>/jp-snapshot-<hex>/snapshot.tar.gz`, where `<temp>` is `sys_get_temp_dir()` and the directory is created with mode `0700` (the nested export re-enters the held lock and skips the integrity check — restoring a broken database is exactly what restore is for);
4. per table: records are read from the archive, normalized against the schema, sorted by `id`, the data file is written, indexes are rebuilt and stamped format v2, `lastInsertedId` is restored as `max(manifest counter, max(id))` — an archive without `counters` falls back to `max(id)` alone; then files the schema does not declare are swept from the table subdirectory (stray indexes of dropped indexes, abandoned `*.tmp`) — after a restore `validate()` is clean of `orphan_index_file`;
5. on success the safety snapshot is deleted;
6. on failure the DB is rolled back from the safety snapshot (schema, data and counters come from the snapshot, including the counters of **its** manifest) and `JsonProviderServiceException` is thrown. When the rollback fails too, both messages plus the snapshot path are present in the exception message so you can recover manually.

Restore serializes against every writer: concurrent inserts/updates either fully complete before the snapshot or start after the restore.

### Default mode: archived data into the current schema

The default mode restores **data only** and lays it out along the **current** database schema — the schema itself is not changed. It is a tool for working with a specific database: you know what you are restoring and into what, and you finish the result yourself. A full restore — schema together with data — is the adopt mode, see below.

What is done:

- the archive's table set must **exactly match** the schema — extra/missing tables → `JsonProviderServiceException`;
- records are laid out along the current schema's columns: a present value is written **as is**, extra keys are dropped, missing columns are back-filled with the type's default (`''`/`0`/`0.0`/`false`, `null` for nullable; a missing not-null temporal column has no default and fails the restore loudly);
- unparseable archive lines are skipped with a `warning` to the PSR-3 logger (when one is attached);
- records are sorted by `id`, indexes are rebuilt, the `lastInsertedId` counter is restored, undeclared files in the table directory are removed.

What is **not** done — values do not go through write validation:

- a value's type is not checked against the column type: a string from the archive lands in an `int` column, a date in a non-canonical form in a `datetime` column;
- a nested array or object in a value is replaced with `null`;
- unique constraints and relations are not checked.

After a restore in this mode, bring the data in line with the schema yourself:

- `validate()` reports unique duplicates, `null` in not-null columns and dangling FKs;
- **`validate()` does not see type mismatches** — check and fix them against your own data (select with `selectAllByArray()`, fix with `updateByArray()` or a migration);
- after manual edits that bypass the provider — `rebuildAllIndexes()` / `repair()`.

## Temporary files

Backup and restore work through temporary files: the intermediate `.jp-backup-<hex>.tar`/`.tar.gz` in the backup's destination directory, a private copy of the archive being restored at `<temp>/jp-restore-<hex>.tar.gz` (mode `0600`), and the safety snapshot. All of them are removed whatever the outcome — success, an exception, or a PHP fatal error (memory exhaustion, `max_execution_time`): in the last case a shutdown handler does the cleanup.

The one exception is **the safety snapshot when it remains the only copy of the state before the restore**:

- **the rollback failed** — the snapshot path is in the `RestoreRollbackFailed` exception message;
- **the restore was interrupted by a fatal error** — the database may be left partially restored; the snapshot path goes to the PSR-3 logger at `critical` level, or, with no logger attached, to the PHP error log right next to the fatal error itself.

In both cases recover from the snapshot with an ordinary `restore()`, then delete its `jp-snapshot-<hex>` directory by hand.

Limitations:

- `kill -9` and a process crash let no cleanup run — `jp-snapshot-*` directories and `jp-restore-*` files may remain in `<temp>`, and `.jp-backup-*` files in the destination directory;
- PHP runs shutdown handlers in registration order: if an application handler registered earlier dies with a fatal error itself, the provider's cleanup never runs;
- under php-fpm `<temp>` may not be the system `/tmp`: with `PrivateTmp=true` in the systemd unit the service gets its own directory (seen from a shell as `/tmp/systemd-private-*-<service>/tmp`), wiped when the service restarts; `sys_temp_dir` in php.ini and the `TMPDIR` variable move it too. Look for a kept snapshot at the path from the log or the exception, not in `/tmp` at random.

## Restoring with the archived schema (adopt)

```php
$db->restore($archive, adoptArchivedSchema: true);
$db->restore($archive, adoptArchivedSchema: true, pruneExtraTables: true);
```

With `adoptArchivedSchema: true` the archived `information_schema.json` **replaces** the current schema — a full restore: schema and data come from the archive together, the path for restoring into an empty DB and for rolling back structural changes. Data lands in the schema it was saved with, so the data/schema mismatches described for the default mode do not arise here. With `pruneExtraTables: true` the database is brought to the archive entirely:

- the archived schema goes through the same strict loaders as the live one: table/column names (path-traversal protection — the name `..` is rejected), the closed type list, the strict relation parser. An invalid schema throws before any mutation; a swapped schema is caught even earlier by its checksum;
- archive tables absent from the current DB are materialized from scratch (data file, indexes, meta);
- current-DB tables absent from the archive **block the restore by default** (`JsonProviderServiceException` with a hint) — data is never dropped silently; with `pruneExtraTables: true` they are removed together with their files and meta;
- a failed restore rolls the schema back too: the rollback re-adopts the snapshot's schema.
