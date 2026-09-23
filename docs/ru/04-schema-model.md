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

Оба пути проверяют индексы и unique-ограничения до того, как схема попадёт на диск: пустой список полей отвергают уже конструкторы `IndexSchema` и `UniqueConstraint` (`IndexFieldsEmpty` / `UniqueConstraintFieldsEmpty`), повтор имени внутри таблицы → `IndexAlreadyExists` / `UniqueConstraintAlreadyExists`, поле, которого нет среди колонок, → `IndexUnknownColumn` / `UniqueConstraintUnknownColumn`.

## Имена таблиц, колонок и индексов

Имя — идентификатор из белого списка: начинается с буквы, цифры или подчёркивания, дальше буквы, цифры, подчёркивания и дефисы; не длиннее 64 символов; без точек и разделителей пути. Правила закреплены в `Schema\IdentifierRules` и проверяются на границе схемы (конструкторы `TableSchema`/`IndexSchema`), поэтому действуют и для DDL-API, и при загрузке `information_schema.json`. Нарушение → `InvalidTableName` / `InvalidColumnName` / `InvalidIndexName`.

Имена таблиц и имена индексов внутри таблицы уникальны **без учёта регистра**: на диске они лежат в нижнем регистре (см. [раскладку хранилища](02-storage-layout.md)), поэтому `Users` при существующей `users` → `TableAlreadyExists`, индекс `IX` рядом с `ix` → `IndexAlreadyExists`, а переименование, меняющее только регистр, отвергается как занятое имя. Схема, в которой такие таблицы уже есть, не загружается → `SchemaTableNamesClash`. Имена колонок — ключи JSON, а не файлы, и остаются регистрозависимыми.

Зарезервировано: префикс `_fk_` у имён индексов (служебные FK-индексы движка) → `ReservedIndexName`; имя таблицы `_pendingRename` (ключ crash-маркера `renameTable` в `meta.json`) → `InvalidTableName`.

Валидное имя по построению не может выйти за пределы каталога БД, когда используется как сегмент пути; в хранилищах дополнительно стоит явный анти-traversal-гвард (`.`, `..`, слэши). `dropTable` с невалидным именем бросает `InvalidTableName`, а не молча ничего не делает.

## Допустимые типы колонок

Тип колонки — это строка-значение в карте `columns`. Список **закрытый**: 12 базовых типов и их `|null`-варианты — ровно 24 строки, перечисленные в `ColumnTypes::all()`. Любая другая строка (`'integer'`, `'datetime|nullable'`, опечатка в правленном руками файле схемы) отклоняется конструктором `TableSchema` с ключом `InvalidColumnType` — и в DDL-API, и при загрузке схемы. У каждого базового типа есть `|null`-вариант (дополнительно разрешает `null`):

- **Примитивы** — `string`, `int`, `float`, `bool`.
- **Дата/время** — `date`, `time`, `timez`, `datetime`, `datetimez`. Абсолютный момент (`datetime`/`datetimez`) хранится в UTC и отдаётся в текущем часовом поясе PHP; wall-clock без момента (`date`/`time`/`timez`) хранится verbatim; см. [Типы даты/времени и часовые пояса](#типы-датывремени-и-часовые-пояса).
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
- `NAN`, `INF` и `-INF` в `float`-колонку не записываются — `NonFiniteFloat` (JSON их не представляет); гвард действует на `insert`/`update`, до касания диска;
- записываемая строка обязана быть корректной UTF-8, иначе `InvalidUtf8` — тоже на `insert`/`update`, до касания диска;
- `null` допустим только для колонки `<type>|null`;
- при `insert` пропуск non-nullable-колонки — ошибка (пропуск nullable → `null`); `update` — это патч: он валидирует только переданные ключи и не трогает остальные;
- ключи, которых нет в схеме, отбрасываются;
- неизвестных/пользовательских строк типов не бывает: их отклоняет граница схемы (`InvalidColumnType`), поэтому каждая колонка всегда проверяется по одному из 24 известных типов.

Нарушения бросают `JsonProviderDataException` с конкретным кейсом — `TypeMismatch`, `NullNotAllowed`, `RequiredColumnMissing`, `NonFiniteFloat`, `InvalidUtf8`, `InvalidTemporalValue`, `ZeroDate`, `NumericPartOutOfRange` — см. [Исключения и локализация](17-exceptions-localization.md).

### Политика типов значений `where`

Значения условий проверяются тем же контрактом, что и запись, — первое несоответствие бросает исключение, запрос не выполняется:

| Оператор | Правило значения |
| - | - |
| `=` | скаляр или `null` (`null` разрешён при любой nullability и просто ищет `null`-ячейки) |
| `>` `>=` `<` `<=` | не-`null` скаляр строго типа колонки |
| `BETWEEN` | массив ровно из двух не-`null` скаляров по правилу диапазонов; иначе `JsonProviderQueryException` |
| `IN` | массив, каждый элемент по правилу `=`; пустой массив валиден и не находит ничего; не-массив → `JsonProviderQueryException` |
| `LIKE` | строка-шаблон; для строковых и `date`/`time`/`timez`-колонок (по хранимой=локальной форме); по `datetime`/`datetimez` → `LikeOnInstantUnsupported` |

Единственная коэрция — `int` в условие по `float`-колонке (`99 → 99.0`); численные **строки** (`'5'` для `int`, `'9.5'` для `float`) отклоняются, как и кросс-типы (`1` для `bool` и т.п.) — `JsonProviderQueryException`. `NAN`/`INF` в условии по `float`-колонке → `NonFiniteFloat` (тот же гвард, что на записи). Условия по `datetime`/`datetimez` кодируются в хранимую UTC-форму; по `date`/`time`/`timez` — сопоставляются с хранимой (verbatim=локальной) формой. Несуществующая колонка в `where`/`orderBy`/`distinct`/`selectColumn` → `QueryUnknownColumn`.

## Формат float на диске

У JSON один числовой тип, поэтому float без дробной части может «схлопнуться» в int при перечитке. Провайдер закрывает это с двух сторон:

- **запись** — float всегда сохраняется с дробной частью (`99.0` → `"price":99.0`, флаг `JSON_PRESERVE_ZERO_FRACTION`), так что перечитка возвращает PHP-`float`;
- **чтение** — во `float`-колонках целые значения расширяются в `float` на каждом пути чтения (полный скан, индексные выборки, `count`, наполнение кэша, DTO-гидрация). Целые значения в файле (`"price":99`, например после внешней правки) поэтому неотличимы от свежих записей: строгие сравнения `=`/`IN` со значением `99.0` их находят.

Переписать такие строки с дробной частью можно однократным `optimizeTable()` по таблице. Нюанс знака: свежезаписанный `-0.0` сохраняет знак, а целый `-0` в файле читается как `int 0` и расширяется в `0.0` без знака.

## Семантика unique-ограничений

Ограничение из `uniqueConstraints` проверяется на `insert` и `update` до записи. Правила соответствуют SQL:

- строка со значением `null` (или отсутствующим полем) в **любом** поле ограничения в проверке не участвует — таких строк может быть сколько угодно, в том числе для составных ограничений;
- конфликт есть только между двумя строками, у которых **все** поля ограничения не-`null` и попарно равны;
- сравнение **типо-строгое**: `1`, `1.0`, `'1'` и `true` — четыре разных значения, они не конфликтуют между собой (числового слияния в стиле SQL нет). В объявленной `float`-колонке `int` расширяется во `float` ещё на записи, поэтому там `1` и `1.0` — один ключ; `-0.0` и `0.0` — тоже один ключ (ключ следует строгому равенству движка сравнения).

Канонизацию значения ключа выполняет `UniqueConstraint::keyPart()`; `UniqueConstraint::keyOf()` возвращает `null` для строк, не участвующих в ограничении.

## Типы даты/времени и часовые пояса

Temporal-колонки существуют ради того, чтобы момент, записанный процессом в одном часовом поясе, читался как тот же момент процессом в другом. Абсолютный момент (`datetime`/`datetimez`) всегда хранится на диске в UTC и конвертируется в текущий часовой пояс PHP (`date_default_timezone_get()`) на выходе. Wall-clock-значения без момента (`date`, `time`, `timez`) хранятся **verbatim** — как есть, без сдвига по TZ. Вся арифметика идёт через `DateTimeImmutable`.

| Тип | Хранение | Сдвиг по TZ | Примечание |
| - | - | - | - |
| `date` | `Y-m-d` (verbatim) | нет | у календарной даты нет момента |
| `time` | `H:i:s` (verbatim) | нет | время суток, точность до секунды |
| `timez` | `H:i:s.v` (verbatim) | нет | время суток с миллисекундами |
| `datetime` | `Y-m-d H:i:s` (UTC) | да | полный момент, точность до секунды |
| `datetimez` | `Y-m-d H:i:s.v` (UTC) | да | полный момент с миллисекундами |
| `year` / `month` / `day` | целое | нет | части с проверкой диапазона |

**Суффикс `z`.** В `timez` и `datetimez` суффикс `z` означает миллисекундную точность, а **не** ISO-маркер `Z` (UTC). Хранение в UTC зависит от того, несёт ли тип момент, а не от суффикса: `datetime` и `datetimez` хранятся в UTC оба, `time` и `timez` — оба как есть, в местном времени без сдвига. Поэтому ISO-`Z` во вводе принимают `datetime`/`datetimez` (см. ниже), а `timez` его отвергает (`InvalidTemporalValue`).

**Доли секунды.** Секундные типы (`time`, `datetime`) отвергают любую дробь; миллисекундные (`timez`, `datetimez`) принимают 1–3 знака и отвергают больше — молчаливого усечения нет (`TemporalFractionUnsupported`). Правило касается строкового ввода; объект `DateTimeImmutable` из DTO усекается до точности колонки, см. [DTO-маппинг](21-dto-mapping.md). У `datetime`/`datetimez` TZ-оффсет вне диапазона ±14:00 (`+25:00`, `+00:99`) → `InvalidTemporalValue`; то же — момент, который после перевода в UTC выходит за годы 0000–9999 (например, `9999-12-31 23:00` в поясе `America/New_York`): хранимая форма держит год четырьмя цифрами, на этом стоит порядок индексов и диапазонов.

Ввод принимается **только** в корректном системном формате — без точек, слешей и обратного порядка, без нулевых дат (`0000-00-00` бросает исключение):

```php
date_default_timezone_set('Europe/Moscow');

$db->table('events')->insertByArray([
    'happensAt' => '2026-07-05 12:30:00',    // на диске 2026-07-05 09:30:00 (UTC)
    'onDate'    => '2026-07-05',              // хранится как есть
    'atTime'    => '23:30:00',               // хранится как есть (verbatim)
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

> `date`, `time` и `timez` намеренно **не** сдвигаются по TZ: у голой даты и у настенного времени нет абсолютного момента, а привязка к локальной полуночи/дате для перевода в UTC не обратима — дата вернула бы другие сутки, а время «дрейфовало» бы под DST. Когда нужна привязанная к поясу точка во времени — используйте `datetime`/`datetimez`.

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
            "onDelete": "cascade",
            "backingIndex": "_fk_ownerId"
        }
    ]
}
```

`type` — один из `belongsTo`, `hasMany`, `hasOne`. `onDelete` и `onUpdate` поддерживают `noAction`, `cascade`, `setNull`, `restrict`. Провайдер применяет действие при удалении записи родителя или изменении referenced-поля. `backingIndex` — служебное поле движка: имя индекса дочерней таблицы, обслуживающего FK-пробы (см. [индексы](06-indexes.md)); проставляется API связей, руками его не заполняют.

### Каноническое направление ребра

Какая сторона — **ребёнок** (физически держит FK-колонку), определяется типом связи:

- `belongsTo`: ребёнок — **from**-таблица (декларирующая), родитель — to. `boards.ownerId -> users.id` объявляется от `boards`.
- `hasMany` / `hasOne`: ребёнок — **to**-таблица, родитель — from. То же ребро в зеркальной нотации: `from: users, to: boards, foreignKey: ownerId`.

`foreignKey` — всегда колонка **ребёнка**, `references` — колонка **родителя**. Обе нотации описывают одно и то же каноническое ребро; движок (каскады, restrict, планы блокировок) работает только с каноническим разрешением, поэтому обе нотации исполняются одинаково.

### Что означают действия

- `onDelete` срабатывает при удалении строки родителя: `cascade` удаляет ссылающихся детей (транзитивно), `setNull` обнуляет их FK, `restrict` запрещает удаление, пока есть хоть одна ссылка (семантика MySQL-immediate: ссылка считается, даже если сам ссылающийся ребёнок удаляется тем же запросом — самоссылочную restrict-таблицу нельзя очистить одним delete-all, удаляйте листья→корни).
- `onUpdate` срабатывает при изменении значения referenced-колонки родителя. На первичном ключе `id` его объявить нельзя (`RelationOnUpdateOnPk`): `id` неизменяем, действие не сработало бы никогда. Работает только для связей на не-PK уникальную колонку — там `cascade` реально переписывает FK-значения детей.

### Объявление связей — только через API

Связи объявляются и снимаются методами `addRelation()` / `dropRelation()` (см. [DDL-операции](12-schema-mutations.md)); ручная правка `information_schema.json` не поддерживается. `addRelation` валидирует декларацию:

1. обе таблицы существуют → иначе `TableNotFound`;
2. FK-колонка есть у ребёнка, referenced-колонка — у родителя → `RelationColumnNotFound` (с подсказкой, в какой таблице живёт FK для данного типа);
3. базовые типы колонок совпадают, суффикс `|null` не учитывается (`int|null` → `int` легально) → `RelationTypeMismatch`;
4. referenced-колонка — это `id` либо покрыта одноколоночным unique-ограничением → `RelationReferencesNotUnique`;
5. `onUpdate` не объявлен на PK → `RelationOnUpdateOnPk`;
6. `setNull` требует nullable FK-колонку → `ForeignKeySetNullNotNullable`;
7. ребро с той же канонической четвёркой (ребёнок.колонка → родитель.колонка) ещё не объявлено — в любой нотации → `RelationAlreadyExists`.

Рёбра, загруженные из схемы, повторно не валидируются при загрузке (загрузка строга только структурно). Их семантику перепроверяет FK-движок в фазе плана — но **только для исполняемых рёбер** (действие ≠ `noAction` для текущего события): мёртвое ребро с несовпадающими типами не мешает работающим delete/update, а исполняемое падает тем же `RelationColumnNotFound`/`RelationTypeMismatch` до какой-либо записи.

### Строгая загрузка схемы

`information_schema.json` парсится строго: структурно битая запись не пропускается молча, а роняет загрузку с точным адресом проблемы — тихо выпавшая связь означала бы, что каскад или restrict, на который рассчитывал автор, просто перестал исполняться.

- запись relation не объект, либо у неё нет строковых `from`/`foreignKey`/`to`/`references` (ловит опечатки вроде `form`), либо неизвестный `type`, либо `backingIndex` присутствует, но не строка → `JsonProviderRelationException`; имена таблиц/колонок в связи проходят тот же белый список идентификаторов, что и сами таблицы;
- `onDelete`/`onUpdate` **присутствует**, но не входит в `noAction`/`cascade`/`setNull`/`restrict` (например `CASCADE` или `set_null`) → `RelationActionInvalid`; отсутствующий ключ — легальный дефолт `noAction`;
- битая запись индекса (нет имени, пустой `fields`, направление вне `asc`/`desc` — регистрозависимо), unique-ограничения (нет имени или полей) или таблицы (определение не объект, тип колонки не строка, отсутствующий ключ `tables`) → `JsonProviderSchemaException` с описанием; пустая коллекция `tables` валидна.

Семантика связей (существование таблиц/колонок, совместимость типов) при загрузке не проверяется — это зона API связей и FK-движка на исполнении.

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
$db->setTableComment('respondents', null);
$db->setColumnComment('respondents', 'formId', 'Идентификатор родительской формы');
$db->setColumnComment('respondents', 'formId', null);
$db->setColumnComments('respondents', [ // заменить всю карту
    'formId'    => 'Идентификатор родительской формы',
    'dedupHash' => 'Хеш анти-дубликата',
]);
$db->setColumnComments('respondents', ['formId' => '...'], merge: true); // домержить поверх
```

`setColumnComment` / `setColumnComments` бросают `JsonProviderSchemaException` с кейсом `ColumnNotFound`, если имя не является объявленной колонкой.

Чтение обратно:

```php
$db->getTableComment('respondents');            // string|null
$db->getColumnComment('respondents', 'formId'); // string|null
$db->getColumnComments('respondents');          $db->describeTable('respondents');
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
