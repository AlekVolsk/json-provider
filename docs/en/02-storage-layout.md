# Storage layout on disk

A storage is a single directory:

```text
db/
├── information_schema.json     # tables, indexes, relations
├── meta.json                   # per-table counters (lastInsertedId, lineCount)
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
- the PK index is named `pk` and always present.

You should not add or remove files inside the storage directory by hand — the integrity validator will flag them as orphans and the repairer will clean them up.
