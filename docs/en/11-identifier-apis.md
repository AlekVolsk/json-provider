# Identifier APIs

Two O(1) reads of `meta.json`:

```php
$last = $db->table('products')->getLastInsertedId();   // 0 if no inserts yet
$next = $db->table('products')->getNextId();           // last + 1
```

`getLastInsertedId()` is the most recently allocated id for the table. After a delete it is **not** rolled back — ids are never reused (the same contract as SQL `AUTO_INCREMENT`).

`getNextId()` predicts the next id but does not reserve it. A concurrent insert can consume the value, leaving the current caller with `last + 2`. Use it for path names or tokens you compute before the insert; if you need strict reservation, allocate the id by performing the insert first.
