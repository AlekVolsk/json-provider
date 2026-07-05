# Модель схемы

Схема описывается классами `TableSchema`, `IndexSchema`, `IndexFieldSchema`, `UniqueConstraint`, `RelationSchema`. Все объекты схемы — иммутабельные value-объекты.

## Создание таблицы

```php
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Query\SortDirectionEnum;

$db->createTable(TableSchema::create(
    name: 'users',
    columns: [
        'email'     => 'string',
        'name'      => 'string',
        'isBlocked' => 'bool',
    ],
    uniqueConstraints: [
        new UniqueConstraint('uq_users_email', ['email']),
    ],
    indexes: [
        new IndexSchema(
            name: 'idx_users_email',
            fields: [new IndexFieldSchema('email', SortDirectionEnum::ASC)],
        ),
    ],
));
```

`createTable()`:

- регистрирует таблицу в `information_schema.json`;
- создаёт файл данных и файлы всех объявленных индексов (пустые);
- создаёт запись меты с `lastInsertedId=0`, `lineCount=0`.

## Два способа собрать `TableSchema`

- `TableSchema::create(...)` — **фабрика с нормализацией**. Сама добавит колонку PK и PK-индекс, принимает «человеческие» описания. Это то, что обычно использует прикладной код.
- `new TableSchema(...)` — **строгий конструктор**. Валидирует контракт, ничего не нормализует. Провайдер использует его при чтении схемы с диска; в тестах удобно для проверок валидации.

## Допустимые типы колонок

Тип колонки — это строка-значение в карте `columns`. У каждого базового типа есть `|null`-вариант (дополнительно разрешает `null`):

- **Примитивы** — `string`, `int`, `float`, `bool`.
- **Дата/время** — `date`, `time`, `timez`, `datetime`, `datetimez`. Значения, несущие момент (всё, кроме голого `date`), хранятся в UTC и отдаются в текущем часовом поясе PHP; см. [Типы даты/времени и часовые пояса](#типы-датывремени-и-часовые-пояса).
- **Числовые части** — `year`, `month`, `day`. Обычные целые с проверкой диапазона, без сдвига по TZ; `year` может быть отрицательным (до н. э.).

Чтобы не рассыпать эти литералы по коду, используйте константы `Schema\ColumnTypes` (класс — только константы):

```php
use AV\JsonProvider\Schema\ColumnTypes;

$db->createTable(TableSchema::create(
    name: 'users',
    columns: [
        'email'     => ColumnTypes::STRING,
        'age'       => ColumnTypes::INT_NULLABLE,
        'isBlocked' => ColumnTypes::BOOL,
        'createdAt' => ColumnTypes::DATETIME,
        'birthDate' => ColumnTypes::DATE_NULLABLE,
    ],
));
```

| Константа | Значение |
| - | - |
| `ColumnTypes::STRING` | `string` |
| `ColumnTypes::INT` | `int` |
| `ColumnTypes::FLOAT` | `float` |
| `ColumnTypes::BOOL` | `bool` |
| `ColumnTypes::STRING_NULLABLE` | `string\|null` |
| `ColumnTypes::INT_NULLABLE` | `int\|null` |
| `ColumnTypes::FLOAT_NULLABLE` | `float\|null` |
| `ColumnTypes::BOOL_NULLABLE` | `bool\|null` |
| `ColumnTypes::DATE` | `date` |
| `ColumnTypes::TIME` | `time` |
| `ColumnTypes::TIMEZ` | `timez` |
| `ColumnTypes::DATETIME` | `datetime` |
| `ColumnTypes::DATETIMEZ` | `datetimez` |
| `ColumnTypes::YEAR` | `year` |
| `ColumnTypes::MONTH` | `month` |
| `ColumnTypes::DAY` | `day` |

У каждого типа есть константа `*_NULLABLE` (например, `ColumnTypes::DATETIME_NULLABLE` → `datetime|null`). Тип PK (`PrimaryKey::TYPE`) переиспользует `ColumnTypes::INT`, так что литерал `int` живёт в одном месте.

Записи содержат скаляры и `null`; вложенные массивы не поддерживаются.

## Валидация значений

Типы проверяются при каждой записи (`insert`, `update` и значения `where`), а не просто документируются. Контракт **строгий**:

- значение обязано точно соответствовать заявленному PHP-типу; единственное послабление — `int` в `float`-колонку (`10 → 10.0`);
- `null` допустим только для колонки `<type>|null`;
- при `insert` пропуск non-nullable-колонки — ошибка (пропуск nullable → `null`); `update` — это патч: он валидирует только переданные ключи и не трогает остальные;
- ключи, которых нет в схеме, отбрасываются;
- неизвестные/пользовательские строки типов пропускаются без проверки (обратная совместимость).

Нарушения бросают `StorageException` с конкретным ключом — `TYPE_MISMATCH`, `NULL_NOT_ALLOWED`, `REQUIRED_COLUMN_MISSING`, `INVALID_TEMPORAL_VALUE`, `ZERO_DATE`, `NUMERIC_PART_OUT_OF_RANGE` — см. [Исключения и локализация](17-exceptions-localization.md).

## Типы даты/времени и часовые пояса

Temporal-колонки существуют ради того, чтобы момент, записанный процессом в одном часовом поясе, читался как тот же момент процессом в другом: значения с моментом всегда хранятся на диске в UTC и конвертируются в текущий часовой пояс PHP (`date_default_timezone_get()`) на выходе. Вся арифметика идёт через `DateTimeImmutable`.

| Тип | Хранение (UTC) | Сдвиг по TZ | Примечание |
| - | - | - | - |
| `date` | `Y-m-d` | нет | у календарной даты нет момента — хранится как есть |
| `time` | `H:i:s` | да | время суток, точность до секунды |
| `timez` | `H:i:s.v` | да | время суток с миллисекундами |
| `datetime` | `Y-m-d H:i:s` | да | полный момент, точность до секунды |
| `datetimez` | `Y-m-d H:i:s.v` | да | полный момент с миллисекундами |
| `year` / `month` / `day` | целое | нет | части с проверкой диапазона |

Ввод принимается **только** в корректном системном формате — без точек, слешей и обратного порядка, без нулевых дат (`0000-00-00` бросает исключение):

```php
date_default_timezone_set('Europe/Moscow'); // UTC+3

$db->table('events')->insertByArray([
    'happensAt' => '2026-07-05 12:30:00',    // на диске 2026-07-05 09:30:00 (UTC)
    'onDate'    => '2026-07-05',              // хранится как есть
    'atTime'    => '23:30:00',               // на диске 20:30:00 (UTC)
]);

// чтение в том же поясе — точный round-trip
$row = $db->table('events')->where('id', '=', 1)->selectOneByArray();
$row['happensAt']; // '2026-07-05 12:30:00'
```

`datetime` / `datetimez` также принимают ISO-формы — разделитель `T`, явный `Z` и числовой сдвиг (`±HH:MM`), всё корректно приводится к UTC:

```text
2026-07-05T12:30:00        // разделитель T, трактуется в локальном поясе
2026-07-05T12:30:00Z       // явный UTC
2026-07-05T12:30:00+05:00  // явный сдвиг -> приводится к UTC
2026-07-05T12:30:00.250Z   // с миллисекундами (datetimez)
```

Значения фильтров кодируются так же, поэтому запрос, сформулированный в **локальном** времени, всё равно попадает в хранимую UTC-форму — в том числе через индекс:

```php
// находит запись, вставленную выше, сравнивая с хранимым UTC-значением
$db->table('events')->where('happensAt', '=', '2026-07-05 12:30:00')->selectOneByArray();
$db->table('events')
    ->where('happensAt', 'BETWEEN', ['2026-07-01 00:00:00', '2026-07-31 23:59:59'])
    ->orderBy('happensAt', 'asc')
    ->selectAllByArray();
```

> Голый `date` намеренно **не** сдвигается по TZ: у даты нет момента, а привязка к локальной полуночи для перевода в UTC не обратима (при чтении вернулись бы другие сутки). Когда нужна привязанная к поясу точка во времени — используйте `datetime`.

## Связи

```php
// внутри information_schema.json
{
    "relations": [
        {
            "from": "boards",
            "foreignKey": "ownerId",
            "to": "users",
            "references": "id",
            "type": "belongsTo",
            "onDelete": "cascade"
        }
    ]
}
```

`type` — один из `belongsTo`, `hasMany`, `hasOne`. `onDelete` и `onUpdate` поддерживают `noAction`, `cascade`, `setNull`, `restrict`. Провайдер применяет действие при удалении записи или изменении referenced-поля.

## Комментарии — описания таблиц и полей

Схема может нести человекочитаемую документацию **в отрыве** от структуры: что за таблица и что означает каждое поле. Комментарии — чистые метаданные: они не влияют ни на контракт PK, ни на выбор индекса, ни на форму записи.

На диске они лежат внутри определения таблицы двумя опциональными ключами: `tableComment` (строка) и `columnComment` (карта `имя поля => описание`, упорядоченная по колонкам):

```json
{
    "tables": {
        "respondents": {
            "tableComment": "Респонденты формы — для дедупликации и учёта отправивших",
            "columns": { "id": "int", "formId": "int", "dedupHash": "string" },
            "columnComment": {
                "dedupHash": "Хеш для защиты от повторной отправки одним человеком"
            },
            "unique": [],
            "indexes": []
        }
    }
}
```

Объявляйте их при создании таблицы через фабрику `TableSchema::create()`. Комментарии к несуществующим колонкам (и пустые строки) молча отбрасываются:

```php
$db->createTable(TableSchema::create(
    name: 'respondents',
    columns: ['formId' => 'int', 'dedupHash' => 'string'],
    tableComment: 'Респонденты формы — для дедупликации и учёта отправивших',
    columnComment: [
        'dedupHash' => 'Хеш для защиты от повторной отправки одним человеком',
    ],
));
```

Комментарии переживают любую мутацию схемы: они заново сохраняются при `createTable` любой таблицы и переносятся через `reorderColumns`. Они же дословно попадают в `backup()` и сохраняются при `restore()`.

Манипулируйте ими независимо от структуры — это schema-only meta-операции: переписывают `information_schema.json`, но не трогают данные таблицы, индексы, мету и кеш:

```php
$db->setTableComment('respondents', 'Респонденты формы');
$db->setTableComment('respondents', null);                       // очистить

$db->setColumnComment('respondents', 'formId', 'Идентификатор родительской формы');
$db->setColumnComment('respondents', 'formId', null);            // очистить одну колонку

$db->setColumnComments('respondents', [                          // заменить всю карту
    'formId'    => 'Идентификатор родительской формы',
    'dedupHash' => 'Хеш анти-дубликата',
]);
$db->setColumnComments('respondents', ['formId' => '...'], merge: true);   // домержить поверх
```

`setColumnComment` / `setColumnComments` бросают `StorageException` с ключом `COLUMN_NOT_FOUND`, если имя не является объявленной колонкой.

Чтение обратно:

```php
$db->getTableComment('respondents');            // string|null
$db->getColumnComment('respondents', 'formId'); // string|null
$db->getColumnComments('respondents');          // array<string,string>

$db->describeTable('respondents');
// [
//   'name' => 'respondents',
//   'comment' => 'Респонденты формы',
//   'columns' => [
//       ['name' => 'id',        'type' => 'int',    'comment' => null],
//       ['name' => 'formId',    'type' => 'int',    'comment' => 'Идентификатор родительской формы'],
//       ...
//   ],
// ]
```
