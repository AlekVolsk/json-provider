# Мутации — insert / update / delete

## Insert

```php
$id = $db->table('products')->insertByArray([
    'name'  => 'Widget',
    'price' => 9.99,
]);   // int — присвоенный id
```

`id` выдаётся провайдером (`lastInsertedId + 1`) и **не переиспользуется** после удаления. Поля, не объявленные в схеме, молча отбрасываются. Поля схемы, отсутствующие в payload, становятся `null`.

Unique-ограничения проверяются перед записью. Нарушение → `StorageException` с ключом `UNIQUE_VIOLATION`.

## Update

DML-методы возвращают `bool`. `true` означает «операция отработала» — пустое множество совпадений — **не** ошибка.

Массовое обновление по условию:

```php
$ok = $db->table('products')
    ->where('categoryId', '=', $cat)
    ->where('active', '=', true)
    ->updateByArray(['price' => 0, 'active' => false]);
```

Обновление одной записи по id:

```php
$ok = $db->table('products')->updateByIdByArray($id, ['price' => 9.99]);
```

Поле `id` в payload-е update-а молча игнорируется — изменить его нельзя.

После update-а билдер помнит число затронутых строк до одного чтения через `affectedRows()`.

## Delete

```php
$db->table('products')->where('discontinued', '=', true)->delete();
$db->table('products')->deleteById($id);
```

Foreign-key-действия (`cascade`, `setNull`, `restrict`) срабатывают автоматически по схеме. `restrict` бросает `FOREIGN_KEY_RESTRICT`, если есть дочерние записи, ссылающиеся на удаляемую.

Ограничение текущего каскадного движка: при циклических связях (A↔B) и самоссылочных таблицах каскадное удаление может не удалить транзитивных потомков (удаляется только прямая цель). Не полагайтесь на каскад в циклических графах — удаляйте от листьев к корням.

## Affected rows

```php
$tbl = $db->table('products');
$tbl->where('discontinued', '=', true)->delete();
echo $tbl->affectedRows();   // число строк последней DML
```

`affectedRows()` равен `0` до первой DML на этом билдере. Read-операции (`select*`, `count`, `exists`, identifier-API) его не меняют.
