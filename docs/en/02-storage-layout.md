# Storage layout on disk

A storage is a single directory:

```text
db/
├── .locks/                     # lock files (see "Concurrency")
│   ├── db.lock
│   ├── table.users.lock
│   └── svc.meta.json.lock
├── information_schema.json     # tables, indexes, relations
├── meta.json                   # per-table counters (lastInsertedId, lineCount, byteSize)
├── users/
│   ├── users.ndjson            # table data, one record per line
│   ├── pk.index.ndjson         # mandatory primary-key index
│   └── idx_users_email.index.ndjson
└── boards/
    ├── boards.ndjson
    ├── pk.index.ndjson
    └── ...
```

Conventions (enforced by the provider):

- one table per subdirectory, named after the table;
- table data file is `<table-name>.ndjson`;
- index files are `<index-name>.index.ndjson`;
- every on-disk name — the table directory, data and index files, lock files, backup archive members — is **lower case**, while the schema keeps names as given: table `Orders` with index `byDate` lives in `orders/orders.ndjson` and `orders/bydate.index.ndjson`. The layout is therefore the same on case-sensitive and case-insensitive file systems, and names differing only in case would share files and count as the same name (see [schema model](04-schema-model.md#table-column-and-index-names));
- the PK index is named `pk` and always present;
- `.locks/` is a service dot-directory with persistent empty lock files; it is excluded from backup and validation, and never needs cleanup (or backing up);
- `*.tmp` siblings of data files are transient files of atomic writes (tmp + fsync + rename); an orphan left by a crashed process is swept by the next write of the same file.

You should not add or remove files inside the storage directory by hand — the integrity validator will flag them as orphans and the repairer will clean them up.
