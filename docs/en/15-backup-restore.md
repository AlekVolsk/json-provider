# Backup and restore

The provider can export the current data state to a single `.tar.gz` archive and restore it back. No external tools or composer packages — uses the bundled `ext-phar` and `ext-zlib` extensions.

## Backup

```php
$archivePath = $db->backup('/var/backups');
// e.g. /var/backups/backup-2026-04-27_173045.tar.gz

$archivePath = $db->backup('/var/backups/snapshot.tar.gz');
// uses the explicit path
```

Behaviour:

- if `$destination` is a directory, the file name is auto-generated with a timestamp;
- if it has no `.tar.gz`/`.tar` extension, `.tar.gz` is appended;
- if the resolved path already exists → `BACKUP_ARCHIVE_EXISTS`;
- if the resolved path is inside the DB directory → `BACKUP_DESTINATION_INSIDE_DB` (a backup file there would itself be flagged as an orphan);
- only the schema and table data are stored — meta and indexes are derivable and are rebuilt on restore.

Archive layout:

```text
manifest.json                 # {format, version, createdAt, tables}
information_schema.json
tables/<table>.ndjson
```

## Restore

```php
$db->restore('/var/backups/snapshot.tar.gz');
```

Behaviour:

1. validate the archive (manifest format, manifest version, table set matches the current schema);
2. take a safety snapshot of the current DB into a temporary archive;
3. for each table: read records from the archive, normalize against the current schema, sort by `id`, write the data file, rebuild indexes, recompute meta;
4. on success — delete the safety snapshot;
5. on failure — roll back from the safety snapshot, raise `RESTORE_FAILED`. If the rollback itself fails, both error messages plus the snapshot path are included in the exception message so you can recover manually.

The archive's table set must **exactly match** the current schema — extra or missing tables raise `BACKUP_SCHEMA_MISMATCH`. Per-record column sets do not need to match: missing columns are filled with `null`, extra columns are dropped.
