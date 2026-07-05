# Структура модуля

```text
JsonProvider/
├── README.md
├── LICENSE
├── docs/                          # руководства: en/, ru/ и иные варианты от сообщества
└── src/
    ├── JsonDataProvider.php       # синглтон-фасад — точка входа в публичный API
    ├── JsonTable.php              # билдер запросов
    ├── JsonFilter.php             # иммутабельный набор условий
    ├── Schema/                    # value-объекты: TableSchema, ColumnTypes, IndexSchema, ...
    ├── Storage/                   # низкоуровневый I/O: NdjsonStorage, JsonStorage
    ├── Registry/                  # SchemaRegistry, MetaRegistry
    ├── Index/                     # IndexManager, IndexKey
    ├── Query/                     # FilterCondition, FilterOperatorEnum, OrderBy, ...
    ├── Cache/                     # CacheInterface + адаптеры
    ├── Exception/                 # JsonProviderException, StorageException, локали
    └── Services/
        ├── Integrity/             # IntegrityValidator, IntegrityRepairer, типы отчёта
        └── Backup/                # Backup, Restore, BackupManifest
```

В обычной работе вы взаимодействуете только с `JsonDataProvider`, `JsonTable`, `JsonFilter`, value-объектами схемы и типами исключений. Storage, registry и сервисные классы доступны, но не являются повседневной поверхностью.
