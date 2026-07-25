# Билдер запросов — JsonTable

`$db->table('name')` возвращает свежий `JsonTable` для таблицы. Билдер — **мутирующий с авто-сбросом**:

- chainable-методы (`where`, `condition`, `orderBy`, `limit`, `offset`, `isDistinct`, `setFilter`) накапливают состояние на `$this`;
- terminal-операции (`selectAll`, `selectOne`, `selectColumn`, `count`, `exists`, `insert`, `update[ById]`, `delete[ById]`) используют накопленное состояние и сбрасывают его — даже при выбросе исключения (через `finally`);
- один и тот же билдер можно сразу же переиспользовать без утечек.

Meta-операции (`affectedRows`, `getLastInsertedId`, `getNextId`, `reorderColumns`, `rebuildIndex`, `rebuildAllIndexes`, `optimizeTable`) **не** используют накопленное состояние и не сбрасывают его.

## Выборка

```php
$all     = $db->table('products')->selectAllByArray();
$first   = $db->table('products')->where('slug', '=', 'apple-iphone')->selectOneByArray();
$count   = $db->table('products')->where('active', '=', true)->count();
$exists  = $db->table('products')->where('slug', '=', 'apple-iphone')->exists();
$slugs   = $db->table('products')->selectColumn('slug');     // list<scalar|null>
```

`selectColumn($field)` возвращает значения одного поля для всех подходящих записей в порядке выборки. `selectOne()` возвращает `null`, если совпадений нет.

## `isDistinct`

Включает дедупликацию по комбинации указанных полей. Порядок конвейера: фильтрация → сортировка → дедупликация → пагинация. Из группы дублей остаётся **первая запись в порядке выдачи**, поэтому `orderBy` определяет, какая именно: с `orderBy('price', 'asc')` от категории останется самая дешёвая позиция, с `desc` — самая дорогая.

```php
$pairs = $db->table('products')->isDistinct('categoryId', 'active')->selectAllByArray();
$cats  = $db->table('products')
    ->where('active', '=', true)
    ->isDistinct('categoryId')
    ->selectColumn('categoryId');
```

## Операторы фильтрации

```php
->where('price', '=',  100)
->where('price', '>',  100)
->where('price', '>=', 100)
->where('price', '<',  1000)
->where('price', '<=', 1000)
->where('title', 'LIKE',    '%phone%')             // % — wildcard
->where('price', 'BETWEEN', [100, 500])
->where('id',    'IN',      [1, 2, 3])

// NOT-вариант любого оператора — четвёртый аргумент true
->where('active', '=',    false,    not: true)
->where('title',  'LIKE', '%draft%', not: true)
```

Несколько `where()` объединяются через **AND**. Прямого `OR` провайдер не поддерживает — выражайте `OR` отдельными запросами или расширением формы данных.

Значения условий валидируются до выполнения запроса — типы зеркалят контракт записи, несуществующая колонка отклоняется (`QueryUnknownColumn`), битая форма `BETWEEN`/`IN` — `JsonProviderQueryException`; полная политика — в [Модели схемы](04-schema-model.md). Строка-«призрак» без какого-то ключа при фильтрации читается так, будто в этом поле `null`.

## Семантика LIKE

LIKE **байтовый и регистрозависимый**. Единственный wildcard — неэкранированный `%` (любая последовательность байтов); `\%` — литеральный процент, `\\` — литеральный обратный слеш; `_` — **не** спецсимвол (матчит литеральный подчёрк). Регистронезависимого варианта (ILIKE) нет — приведение регистра остаётся на прикладном уровне. Шаблон обязан быть строкой, колонка — строковой либо `date`/`time`/`timez` (сопоставляется с хранимой = локальной формой). По `datetime`/`datetimez` LIKE **не поддерживается** (значение хранится в UTC, а не в локальной форме) → `LikeOnInstantUnsupported`; используйте `=`/`BETWEEN`.

## Режим сравнения строк

Порядковые операторы (`>`, `>=`, `<`, `<=`, `BETWEEN`) и `orderBy` для пар строк по умолчанию **байтовые** (`ComparisonMode::Binary`): `'10' < '9'`, числовые строки не коэрцируются, порядок совпадает с байтовым порядком индексов — диапазон, сортировка и индексный путь дают один ответ. Числа сравниваются численно; `null` минимален; `=`/`IN` — строгое `===` всегда.

`$db->setComparisonMode(ComparisonMode::Locale)` включает для пар строк коллатор ext-intl (естественно-языковой порядок). Режим влияет **только на порядок**: `=`/`IN`/`LIKE` остаются точными и индексируемыми, а сортировка/диапазоны по строковым колонкам перестают использовать индекс (байтовый порядок индекса несовместим с коллатором) и идут full scan'ом. Без ext-intl `Locale` тихо ведёт себя как `Binary`; зависимость объявлена в composer `suggest`.

## Сортировка

```php
$db->table('products')
    ->where('categoryId', '=', 3)
    ->orderBy('price', 'asc')
    ->orderBy('title', 'desc')   // вторичный ключ при равенстве первого
    ->selectAllByArray();
```

## Пагинация

```php
$page    = 2;
$perPage = 20;

$db->table('products')
    ->where('active', '=', true)
    ->orderBy('createdAt', 'desc')
    ->limit($perPage)
    ->offset(($page - 1) * $perPage)
    ->selectAllByArray();
```

Когда ordering-индекс покрывает `orderBy`, пагинация применяется на уровне индекса — из файла данных читается только запрошенный срез.

Отрицательные `limit`/`offset` отклоняются fail-fast (`InvalidLimit`/`InvalidOffset`); `limit(0)` валиден и даёт пустую выдачу (как SQL `LIMIT 0`). Направление `orderBy` регистронезависимо (`'asc'`/`'DESC'`/`'Desc'`), любое другое значение — `InvalidSortDirection`, а не тихая сортировка по возрастанию.
