# Reusable filters — JsonFilter

`JsonFilter` is an immutable bag of conditions. Build it once, attach to many queries via `setFilter()`. Every `where()` returns a new `JsonFilter`, the original stays unchanged.

```php
use AV\JsonProvider\JsonFilter;

$filter = (new JsonFilter())
    ->where('categoryId', '=', $categoryId)
    ->where('active', '=', true);

$total   = $db->table('products')->setFilter($filter)->count();
$page    = $db->table('products')->setFilter($filter)->orderBy('price', 'asc')->limit(10)->selectAllByArray();
$cheap   = $db->table('products')->setFilter($filter)->where('price', '<=', 1000)->selectAllByArray();
```

`setFilter()` **replaces** the accumulated `where` / `condition` list on the builder. Subsequent `where()` calls are appended (AND-joined) on top of the filter's conditions.
