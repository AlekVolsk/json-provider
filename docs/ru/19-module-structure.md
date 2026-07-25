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
    ├── Storage/                   # низкоуровневый I/O: NdjsonStorage, JsonStorage, локи
    ├── Registry/                  # SchemaRegistry, MetaRegistry
    ├── Index/                     # IndexManager, IndexKey
    ├── Query/                     # FilterCondition, FilterOperatorEnum, OrderBy, ...
    ├── Relations/                 # FkEngine — планы и применение FK-действий
    ├── Validation/                # ValueValidator, TemporalCodec — контракт значений
    ├── Mapping/                   # DTO-отображение: DtoMap, DtoMapper, атрибуты
    ├── Cache/                     # CacheInterface + адаптеры
    ├── Exception/                 # базовый класс + девять доменных
    │   └── Locale/                # словарь ситуаций: En, Ru, LocaleInterface
    └── Services/
        ├── Integrity/             # IntegrityValidator, IntegrityRepairer, типы отчёта
        └── Backup/                # Backup, Restore, BackupManifest
```

В обычной работе вы взаимодействуете только с `JsonDataProvider`, `JsonTable`, `JsonFilter`, value-объектами схемы, атрибутами DTO-отображения и типами исключений. Storage, registry, движок связей и сервисные классы доступны, но не являются повседневной поверхностью.
