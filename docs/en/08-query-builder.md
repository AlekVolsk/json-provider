# Query builder — JsonTable

`$db->table('name')` returns a fresh `JsonTable` for that table. The builder is **mutable with auto-reset**:

- chainable methods (`where`, `condition`, `orderBy`, `limit`, `offset`, `isDistinct`, `setFilter`) accumulate state on `this`;
- terminal operations (`selectAll`, `selectOne`, `selectColumn`, `count`, `exists`, `insert`, `update[ById]`, `delete[ById]`) consume the state and clear it — even if they throw (via `finally`);
- the same builder can be reused for the next query without leaking state.

Meta operations (`affectedRows`, `getLastInsertedId`, `getNextId`, `reorderColumns`, `rebuildIndex`, `rebuildAllIndexes`, `optimizeTable`) do **not** consume or change accumulated state.

## Selection

```php
$all     = $db->table('products')->selectAllByArray();
$first   = $db->table('products')->where('slug', '=', 'apple-iphone')->selectOneByArray();
$count   = $db->table('products')->where('active', '=', true)->count();
$exists  = $db->table('products')->where('slug', '=', 'apple-iphone')->exists();
$slugs   = $db->table('products')->selectColumn('slug');     // list<scalar|null>
```

`selectColumn($field)` returns the values of one field from all matching records, in selection order. `selectOne()` returns `null` if no match.

## `isDistinct`

Enables deduplication by the combination of given fields. Applied after filtering, before sorting and pagination.

```php
$pairs = $db->table('products')->isDistinct('categoryId', 'active')->selectAllByArray();
$cats  = $db->table('products')
    ->where('active', '=', true)
    ->isDistinct('categoryId')
    ->selectColumn('categoryId');
```

## Filter operators

```php
->where('price', '=',  100)
->where('price', '>',  100)
->where('price', '>=', 100)
->where('price', '<',  1000)
->where('price', '<=', 1000)
->where('title', 'LIKE',    '%phone%')             // % is the wildcard
->where('price', 'BETWEEN', [100, 500])
->where('id',    'IN',      [1, 2, 3])

// NOT variant of any operator — fourth arg true
->where('active', '=',    false,    not: true)
->where('title',  'LIKE', '%draft%', not: true)
```

Multiple `where()` calls are joined with **AND**. The provider does not support `OR` directly — express `OR` by issuing separate queries or by broadening the data shape.

Condition values are validated before the query executes — types mirror the write contract, a column missing from the schema is rejected (`QUERY_UNKNOWN_COLUMN`), a malformed `BETWEEN`/`IN` shape raises `CONDITION_MALFORMED`; the full policy lives in the [Schema model](04-schema-model.md). A ghost row lacking some key is filtered as if that field were `null`.

## LIKE semantics

LIKE is **bytewise and case-sensitive**. The only wildcard is an unescaped `%` (any byte run); `\%` is a literal percent, `\\` a literal backslash; `_` is **not** special (it matches a literal underscore). There is no case-insensitive variant (ILIKE) — case folding stays at the application level. For temporal columns the pattern matches against the stored UTC form. The pattern must be a string and the column string/temporal.

## String comparison mode

The ordering operators (`>`, `>=`, `<`, `<=`, `BETWEEN`) and `orderBy` compare string pairs **bytewise** by default (`ComparisonMode::Binary`): `'10' < '9'`, numeric strings are not coerced, and the order matches the byte order of indexes — a range, a sort and the index path give one answer. Numbers compare numerically; `null` sorts first; `=`/`IN` stay strict `===` always.

`$db->setComparisonMode(ComparisonMode::Locale)` switches string pairs to the ext-intl collator (natural-language order). The mode affects **order only**: `=`/`IN`/`LIKE` remain exact and indexable, while ordering/ranges over string columns stop using indexes (the byte-ordered index disagrees with the collator) and run as full scans. Without ext-intl, `Locale` silently behaves as `Binary`; the dependency is declared in composer `suggest`.

## Ordering

```php
$db->table('products')
    ->where('categoryId', '=', 3)
    ->orderBy('price', 'asc')
    ->orderBy('title', 'desc')   // secondary key for ties
    ->selectAllByArray();
```

## Pagination

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

When an ordering index covers the `orderBy`, pagination is applied at the index layer — only the requested slice is read from the data file.

Negative `limit`/`offset` values are rejected fail-fast (`INVALID_LIMIT`/`INVALID_OFFSET`); `limit(0)` is valid and yields an empty result (cf. SQL `LIMIT 0`). The `orderBy` direction is case-insensitive (`'asc'`/`'DESC'`/`'Desc'`); anything else raises `INVALID_SORT_DIRECTION` instead of silently sorting ascending.
