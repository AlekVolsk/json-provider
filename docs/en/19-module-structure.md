# Module structure

```text
JsonProvider/
├── README.md
├── LICENSE
├── docs/                          # guides: en/, ru/ and other translations from the community
└── src/
    ├── JsonDataProvider.php       # singleton facade — public API entry point
    ├── JsonTable.php              # query builder
    ├── JsonFilter.php             # immutable conditions bag
    ├── Schema/                    # value objects: TableSchema, ColumnTypes, IndexSchema, ...
    ├── Storage/                   # low-level I/O: NdjsonStorage, JsonStorage, locks
    ├── Registry/                  # SchemaRegistry, MetaRegistry
    ├── Index/                     # IndexManager, IndexKey
    ├── Query/                     # FilterCondition, FilterOperatorEnum, OrderBy, ...
    ├── Relations/                 # FkEngine — planning and applying FK actions
    ├── Validation/                # ValueValidator, TemporalCodec — the value contract
    ├── Mapping/                   # DTO mapping: DtoMap, DtoMapper, attributes
    ├── Cache/                     # CacheInterface + adapters
    ├── Exception/                 # the base class + nine domain ones
    │   └── Locale/                # the vocabulary: En, Ru, LocaleInterface
    └── Services/
        ├── Integrity/             # IntegrityValidator, IntegrityRepairer, report types
        └── Backup/                # Backup, Restore, BackupManifest
```

You will normally interact only with `JsonDataProvider`, `JsonTable`, `JsonFilter`, the schema value objects, the DTO mapping attributes and the exception types. Storage, registry, the relations engine and the service classes are accessible but are not the day-to-day surface.
