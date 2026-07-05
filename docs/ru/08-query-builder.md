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

Включает дедупликацию по комбинации указанных полей. Применяется после фильтрации, до сортировки и пагинации.

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
