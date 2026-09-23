# Query builder — JsonTable

`$db->table('name')` returns a fresh `JsonTable` for that table. The builder is **mutable with auto-reset**:

- chainable methods (`where`, `condition`, `orderBy`, `limit`, `offset`, `distinct`, `setFilter`) accumulate state on `this`;
- terminal operations (`selectAll`, `selectOne`, `selectColumn`, `count`, `exists`, `insert`, `update[ById]`, `delete[ById]`) consume the state and clear it — even if they throw (via `finally`);
- the same builder can be reused for the next query without leaking state.

Meta operations (`affectedRows`, `getLastInsertedId`, `getNextId`, `reorderColumns`, `rebuildIndex`, `rebuildAllIndexes`, `optimizeTable`) do **not** consume or change accumulated state.

The same data access without a builder and without state — [direct provider methods](22-direct-provider-api.md).

## Selection

```php
$all     = $db->table('products')->selectAllByArray();
$first   = $db->table('products')->where('slug', '=', 'apple-iphone')->selectOneByArray();
$count   = $db->table('products')->where('active', '=', true)->count();
$exists  = $db->table('products')->where('slug', '=', 'apple-iphone')->exists();
$slugs   = $db->table('products')->selectColumn('slug');     // list<scalar|null>
```

`selectColumn($field)` returns the values of one field from all matching records, in selection order. `selectOne()` returns `null` if no match.

## `distinct`

Enables deduplication by the combination of given fields. The pipeline order is: filtering → sorting → deduplication → pagination. Each group of duplicates keeps its **first row in result order**, so `orderBy` decides which one survives: with `orderBy('price', 'asc')` a category keeps its cheapest row, with `desc` its most expensive one.

```php
$pairs = $db->table('products')->distinct('categoryId', 'active')->selectAllByArray();
$cats  = $db->table('products')
    ->where('active', '=', true)
    ->distinct('categoryId')
    ->selectColumn('categoryId');
```

`distinct` compares values more strictly than `=`: `-0.0` and `0.0` are **two different** values, although `=`, unique constraints and indexes treat them as one. The sign of zero is stored on disk, and `distinct` shows the data as it is. If you need a single zero, normalize the values on write.

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

### `null` and `NOT`

Condition logic is **two-valued**, not three-valued as in SQL:

- a comparison with a non-`null` value (`=`, `>`, `<`, `BETWEEN`, `IN`, `LIKE`) does not match a row holding `null` in that field — as in SQL;
- `where('f', '=', null)` matches rows holding `null` — the counterpart of `IS NULL`; with `not: true` — of `IS NOT NULL`;
- `not: true` is the exact negation of the condition: the result gets **everything** the condition did not match, **including rows holding `null`**. In SQL `NOT (price > 10)` would not return `NULL` rows (a comparison with `NULL` is UNKNOWN, and `NOT UNKNOWN` is UNKNOWN too); here it does.

When `null` rows are unwanted in a negation, exclude them explicitly:

```php
$db->table('products')
    ->where('price', '>', 10, not: true)
    ->where('price', '=', null, not: true)   // non-null only
    ->selectAllByArray();
```

Condition values are validated before the query executes — types mirror the write contract, a column missing from the schema is rejected (`QueryUnknownColumn`), a malformed `BETWEEN`/`IN` shape raises `JsonProviderQueryException`; the full policy lives in the [Schema model](04-schema-model.md). A ghost row lacking some key is filtered as if that field were `null`.

## LIKE semantics

LIKE is **bytewise and case-sensitive**. The only wildcard is an unescaped `%` (any byte run); `\%` is a literal percent, `\\` a literal backslash; `_` is **not** special (it matches a literal underscore). There is no case-insensitive variant (ILIKE) — case folding stays at the application level. The pattern must be a string and the column string or `date`/`time`/`timez` (matched against the stored=local form). LIKE on `datetime`/`datetimez` is **not supported** (the value is stored in UTC, not the local form) → `LikeOnInstantUnsupported`; use `=`/`BETWEEN` instead.

## String comparison mode

The ordering operators (`>`, `>=`, `<`, `<=`, `BETWEEN`) and `orderBy` compare string pairs **bytewise** by default (`ComparisonModeEnum::Binary`): `'10' < '9'`, numeric strings are not coerced, and the order matches the byte order of indexes — a range, a sort and the index path give one answer. Numbers compare numerically; `null` sorts first; `=`/`IN` stay strict `===` always.

`$db->setComparisonMode(ComparisonModeEnum::Locale)` switches string pairs to the ext-intl collator (natural-language order). The mode affects **order only**: `=`/`IN`/`LIKE` remain exact and indexable, while ordering/ranges over string columns stop using indexes (the byte-ordered index disagrees with the collator) and run as full scans. Without ext-intl, `Locale` silently behaves as `Binary`; the dependency is declared in composer `suggest`.

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

Negative `limit`/`offset` values are rejected fail-fast (`InvalidLimit`/`InvalidOffset`); `limit(0)` is valid and yields an empty result (cf. SQL `LIMIT 0`). The `orderBy` direction is case-insensitive (`'asc'`/`'DESC'`/`'Desc'`); anything else raises `InvalidSortDirection` instead of silently sorting ascending.
