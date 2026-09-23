# Direct provider methods — select / count / readAll / insert / update / delete

`JsonDataProvider` has data-access methods that need no builder: every call receives everything explicitly — table name, conditions, ordering, pagination — and nothing accumulates between calls. [`JsonTable`](08-query-builder.md) itself is built on them: its terminal operations collect the accumulated state and hand it over here.

The two surfaces serve different purposes:

- **`JsonTable`** — for application code: the `where()->orderBy()->limit()` chain, DTO methods, `affectedRows()`;
- **direct methods** — for services and repositories that assemble conditions themselves (e.g. from a `JsonFilter`) and want one stateless call. They work with arrays only; there are no DTOs here.

Execution is the same: the same locks, value and condition validation, unique checks, FK actions and cache as the builder.

## Conditions and ordering

Conditions are a list of `FilterCondition`, all AND-combined; ordering is a list of `OrderBy`. The easiest way to build conditions is a [`JsonFilter`](09-reusable-filters.md):

```php
use AV\JsonProvider\JsonFilter;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\OrderBy;

$active = (new JsonFilter())->where('active', '=', true)->getConditions();

$cheap = [new FilterCondition('price', FilterOperatorEnum::LT, 10.0)];
$order = [OrderBy::desc('price')];
```

Condition values are checked by the same [`where` value policy](04-schema-model.md); an unknown column → `QueryUnknownColumn`.

## Reading

```php
$rows  = $db->select('products', $active, $order, limit: 10, offset: 0);
$rows  = $db->select('products', distinctFields: ['category']);
$total = $db->count('products', $active);
$all   = $db->readAll('products');
```

- `select(table, conditions = [], ordering = [], limit = null, offset = 0, distinctFields = [])` — an array of records. The pipeline is the builder's: filter → sort → distinct → offset/limit; an index is used when one exists and can be trusted. A negative `limit` → `InvalidLimit`, a negative `offset` → `InvalidOffset`.
- `count(table, conditions = [])` — the number of records. With no conditions it answers from the meta counter without reading data, when that counter is reliable. There is no distinct here — to count distinct values use the builder: `$db->table('t')->distinct('f')->count()`.
- `readAll(table)` — every record of the table, no filters or pagination, through the cache.

## Writing

```php
$id      = $db->insert('products', ['name' => 'Widget', 'price' => 9.99, 'active' => true]);
$updated = $db->update('products', $cheap, ['active' => false]);
$deleted = $db->delete('products', [new FilterCondition('active', FilterOperatorEnum::EQ, false)]);
```

- `insert(table, record)` — returns the assigned `id`, like `insertByArray()`.
- `update(table, conditions, data)` — applies one patch to every matching record and returns their **count** (0 is not an error). An `id` key in the patch is silently dropped.
- `delete(table, conditions)` — deletes the matching records and returns their **count**. **An empty condition list deletes every record of the table.**

FK actions, unique checks and the two-phase write are the ones described in [mutations](10-mutations.md).

## Differences from `JsonTable`

| | `JsonTable` | Direct methods |
| - | - | - |
| State | accumulated by the chain, reset by the terminal operation | none, everything is passed in the call |
| `update`/`delete` result | `bool` + row count via `affectedRows()` | number of affected rows |
| DTO | `insert`/`update`/`selectAll`/`selectOne` | arrays only |
| Distinct in counting | `distinct(...)->count()` | none |
| All records, no conditions | `selectAllByArray()` | `readAll()` |
