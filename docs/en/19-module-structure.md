# Module structure

```text
JsonProvider/
├── README.md
├── LICENSE
├── docs/                          # guides: en/ and other translations from the community
└── src/
    ├── JsonDataProvider.php       # singleton facade — public API entry point
    ├── JsonTable.php              # query builder
    ├── JsonFilter.php             # immutable conditions bag
    ├── Schema/                    # value objects: TableSchema, ColumnTypes, IndexSchema, ...
    ├── Storage/                   # low-level I/O: NdjsonStorage, JsonStorage
    ├── Registry/                  # SchemaRegistry, MetaRegistry
    ├── Index/                     # IndexManager, IndexKey
    ├── Query/                     # FilterCondition, FilterOperatorEnum, OrderBy, ...
    ├── Cache/                     # CacheInterface + adapters
    ├── Exception/                 # JsonProviderException, StorageException, locales
    └── Services/
        ├── Integrity/             # IntegrityValidator, IntegrityRepairer, report types
        └── Backup/                # Backup, Restore, BackupManifest
```

You will normally interact only with `JsonDataProvider`, `JsonTable`, `JsonFilter`, the schema value objects and the exception types. Storage, registry and service classes are accessible but are not the day-to-day surface.
