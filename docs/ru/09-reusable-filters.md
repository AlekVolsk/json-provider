# Переиспользуемые фильтры — JsonFilter

`JsonFilter` — иммутабельный набор условий. Собирается один раз, прикрепляется к множеству запросов через `setFilter()`. Каждый `where()` возвращает новый `JsonFilter`, исходный не меняется.

```php
use AV\JsonProvider\JsonFilter;

$filter = (new JsonFilter())
    ->where('categoryId', '=', $categoryId)
    ->where('active', '=', true);

$total   = $db->table('products')->setFilter($filter)->count();
$page    = $db->table('products')->setFilter($filter)->orderBy('price', 'asc')->limit(10)->selectAllByArray();
$cheap   = $db->table('products')->setFilter($filter)->where('price', '<=', 1000)->selectAllByArray();
```

`setFilter()` **заменяет** накопленный список `where`/`condition` на билдере. Последующие `where()` дописываются (через AND) поверх условий фильтра.
