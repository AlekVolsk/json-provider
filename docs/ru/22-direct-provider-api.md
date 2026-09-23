# Прямые методы провайдера — select / count / readAll / insert / update / delete

У `JsonDataProvider` есть методы доступа к данным без билдера: каждый вызов получает всё явно — имя таблицы, условия, сортировку, пагинацию — и ничего не накапливает между вызовами. На них построен сам [`JsonTable`](08-query-builder.md): его терминальные операции собирают накопленное состояние и передают сюда.

Назначение у двух поверхностей разное:

- **`JsonTable`** — для прикладного кода: цепочка `where()->orderBy()->limit()`, DTO-методы, `affectedRows()`;
- **прямые методы** — для сервисов и репозиториев, которые сами собирают условия (например, из `JsonFilter`) и хотят один вызов без состояния. Работают только с массивами, DTO здесь нет.

Исполнение одно и то же: те же блокировки, валидация значений и условий, unique-проверки, FK-действия и кеш, что и у билдера.

## Условия и сортировка

Условия — список `FilterCondition`, все объединяются через AND; сортировка — список `OrderBy`. Удобнее всего собирать условия через [`JsonFilter`](09-reusable-filters.md):

```php
use AV\JsonProvider\JsonFilter;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\OrderBy;

$active = (new JsonFilter())->where('active', '=', true)->getConditions();

$cheap = [new FilterCondition('price', FilterOperatorEnum::LT, 10.0)];
$order = [OrderBy::desc('price')];
```

Значения условий проверяются по той же [политике `where`](04-schema-model.md), неизвестная колонка → `QueryUnknownColumn`.

## Чтение

```php
$rows  = $db->select('products', $active, $order, limit: 10, offset: 0);
$rows  = $db->select('products', distinctFields: ['category']);
$total = $db->count('products', $active);
$all   = $db->readAll('products');
```

- `select(table, conditions = [], ordering = [], limit = null, offset = 0, distinctFields = [])` — массив записей. Конвейер тот же, что у билдера: фильтр → сортировка → distinct → offset/limit; индекс используется, когда он есть и ему можно доверять. Отрицательный `limit` → `InvalidLimit`, отрицательный `offset` → `InvalidOffset`.
- `count(table, conditions = [])` — число записей. Без условий отвечает из счётчика меты, не читая данные, если счётчик достоверен. Distinct здесь нет — для подсчёта уникальных значений используйте билдер: `$db->table('t')->distinct('f')->count()`.
- `readAll(table)` — все записи таблицы без фильтров и пагинации, через кеш.

## Запись

```php
$id      = $db->insert('products', ['name' => 'Widget', 'price' => 9.99, 'active' => true]);
$updated = $db->update('products', $cheap, ['active' => false]);
$deleted = $db->delete('products', [new FilterCondition('active', FilterOperatorEnum::EQ, false)]);
```

- `insert(table, record)` — возвращает назначенный `id`, как `insertByArray()`.
- `update(table, conditions, data)` — применяет один патч ко всем подходящим записям и возвращает их **число** (0 — не ошибка). Ключ `id` в патче молча отбрасывается.
- `delete(table, conditions)` — удаляет подходящие записи и возвращает их **число**. **Пустой список условий удаляет все записи таблицы.**

FK-действия, unique-проверки и двухфазная запись — те же, что описаны в [мутациях](10-mutations.md).

## Отличия от `JsonTable`

| | `JsonTable` | Прямые методы |
| - | - | - |
| Состояние | накапливается цепочкой и сбрасывается терминальной операцией | нет, всё передаётся в вызов |
| Результат `update`/`delete` | `bool` + число строк через `affectedRows()` | число затронутых строк |
| DTO | `insert`/`update`/`selectAll`/`selectOne` | только массивы |
| Distinct в подсчёте | `distinct(...)->count()` | нет |
| Все записи без условий | `selectAllByArray()` | `readAll()` |
