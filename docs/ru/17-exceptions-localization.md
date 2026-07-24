# Исключения и локализация

Все исключения провайдера наследуют `AV\JsonProvider\Exception\JsonProviderException`. Конкретный класс для storage-ошибок — `StorageException`.

```php
use AV\JsonProvider\Exception\StorageException;

try {
    $db->table('users')->insertByArray(['email' => $existing]);
} catch (StorageException $e) {
    $e->getMessage();           // английский (для логов)
    $e->getLocalizedMessage();  // текущая локаль (для пользователя)
    $e->getErrorKey();          // например 'UNIQUE_VIOLATION' — для программной обработки
}
```

## Установка локали

```php
use AV\JsonProvider\Exception\LangRuEnum;

$db->setLocale(LangRuEnum);  // любой кейс — важен только класс
```

Локаль действует на весь процесс. `getMessage()` всегда остаётся английским (стабильные строки логов). `getLocalizedMessage()` отдаёт перевод; неизвестные ключи откатываются на английский.

## Своя локаль

```php
use AV\JsonProvider\Exception\LocaleInterface;

enum LangDeEnum: string implements LocaleInterface
{
    case TABLE_NOT_FOUND  = 'Tabelle "%s" nicht im Schema gefunden';
    case UNIQUE_VIOLATION = 'Eindeutigkeitsverletzung in "%s" auf Feldern [%s]: %s';
    // отсутствующие ключи откатываются на английский

    public static function translate(string $key, string ...$params): string
    {
        foreach (self::cases() as $case) {
            if ($case->name === $key) {
                return $params !== [] ? \sprintf($case->value, ...$params) : $case->value;
            }
        }
        return $key;   // вернуть ключ → fallback на английский
    }
}

$db->setLocale(LangDeEnum::TABLE_NOT_FOUND);
```

Класс кастомной локали может находиться в любом namespace.

## Частые ключи ошибок

| Ключ | Когда |
| - | - |
| `TABLE_NOT_FOUND` | неизвестное имя таблицы |
| `TABLE_ALREADY_EXISTS` | `createTable` для уже зарегистрированного имени |
| `COLUMN_NOT_FOUND` | comment-API вызван для несуществующей колонки |
| `DATABASE_ALREADY_EXISTS` | `createDatabase` по уже существующему пути |
| `UNIQUE_VIOLATION` | нарушение unique-ограничения на insert/update |
| `FOREIGN_KEY_RESTRICT` | действие `restrict` заблокировало удаление/обновление |
| `PK_CONTRACT_VIOLATED` | нарушение контракта PK или PK-индекса |
| `META_ENTRY_MISSING` | нет записи в мете для зарегистрированной таблицы |
| `META_ENTRY_CORRUPT` | запись меты повреждена (счётчик не int) — чинится `repair()` |
| `INDEX_NOT_FOUND` | названного индекса нет в схеме таблицы |
| `REORDER_COLUMNS_UNKNOWN` | reorderColumns: колонка не из схемы |
| `REORDER_COLUMNS_DUPLICATE` | reorderColumns: дубликат имени колонки |
| `REORDER_COLUMNS_INCOMPLETE` | reorderColumns: пропущены колонки |
| `MIGRATE_COLUMN_TYPE_CHANGE` | migrateColumns: у удерживаемой колонки меняется тип |
| `MIGRATE_COLUMN_NO_DEFAULT` | migrateColumns: not-null-колонка без дефолта добавлена в непустую таблицу |
| `MIGRATE_FIELD_UNKNOWN_COLUMN` | ограничение/индекс ссылается на отсутствующую колонку (migrateColumns, addIndex, addUniqueConstraint) |
| `INVALID_TABLE_NAME` | имя таблицы вне белого списка идентификаторов (или зарезервированное `_pendingRename`) |
| `INVALID_COLUMN_NAME` | имя колонки вне белого списка идентификаторов |
| `INVALID_INDEX_NAME` | имя индекса вне белого списка идентификаторов |
| `RESERVED_INDEX_NAME` | имя индекса начинается с зарезервированного префикса `_fk_` |
| `INVALID_COLUMN_TYPE` | тип колонки вне закрытого списка 24 типов |
| `RELATION_ENTRY_INVALID` | битая запись relation в information_schema.json (ключи/тип) |
| `RELATION_ACTION_INVALID` | onDelete/onUpdate вне noAction/cascade/setNull/restrict |
| `RELATION_COLUMN_NOT_FOUND` | FK/referenced-колонка не существует в канонической таблице связи |
| `RELATION_TYPE_MISMATCH` | базовые типы FK- и referenced-колонки не совпадают |
| `RELATION_ALREADY_EXISTS` | addRelation: каноническое ребро уже объявлено (в любой нотации) |
| `RELATION_NOT_FOUND` | dropRelation: связь по тройке (from, foreignKey, to) не объявлена |
| `RELATION_REFERENCES_NOT_UNIQUE` | references не PK и не покрыта одноколоночным unique |
| `RELATION_ON_UPDATE_ON_PK` | onUpdate объявлен на неизменяемом PK `id` |
| `FOREIGN_KEY_SET_NULL_NOT_NULLABLE` | setNull на non-nullable FK-колонке (декларация или исполнение) |
| `FK_BACKING_INDEX_MISSING` | restrict-проба без покрывающего backing-индекса — нужен `repair()` |
| `INDEX_ALREADY_EXISTS` | addIndex: имя индекса уже занято |
| `UNIQUE_CONSTRAINT_ALREADY_EXISTS` | addUniqueConstraint: имя ограничения уже занято |
| `UNIQUE_CONSTRAINT_NOT_FOUND` | dropUniqueConstraint: названного ограничения нет |
| `COLUMN_ALREADY_EXISTS` | renameColumn: целевое имя колонки занято |
| `RENAME_INCOMPLETE` | запись/DDL в таблицу с живым маркером `_pendingRename` — сначала `repair()` |
| `BACKUP_ARCHIVE_EXISTS` | целевой путь бэкапа занят |
| `BACKUP_DESTINATION_INSIDE_DB` | целевой путь бэкапа внутри каталога БД |
| `BACKUP_ARCHIVE_CORRUPT` | restore: архив отсутствует/нечитаем/неверный формат |
| `BACKUP_SCHEMA_MISMATCH` | restore: набор таблиц в архиве не совпадает со схемой |
| `BACKUP_CHECKSUM_MISMATCH` | restore: член архива не прошёл sha256-проверку манифеста |
| `RESTORE_FAILED` | restore не удался (откачен, если возможно) |
| `EXTENSION_REQUIRED` | кеш-адаптер не нашёл своё PHP-расширение |
| `TYPE_MISMATCH` | значение не соответствует заявленному типу колонки |
| `NULL_NOT_ALLOWED` | `null` для non-nullable-колонки |
| `REQUIRED_COLUMN_MISSING` | insert пропустил non-nullable-колонку |
| `NON_FINITE_FLOAT` | `NAN`/`INF` во float-колонку — не представимы в JSON |
| `INVALID_UTF8` | строка не является корректной UTF-8 |
| `QUERY_UNKNOWN_COLUMN` | колонка из where/orderBy/distinct/selectColumn отсутствует в схеме |
| `CONDITION_TYPE_MISMATCH` | значение условия where не соответствует типу колонки/оператору |
| `CONDITION_MALFORMED` | битая форма BETWEEN/IN (не массив, не [min, max]) |
| `INVALID_SORT_DIRECTION` | направление orderBy не "asc"/"desc" |
| `INVALID_LIMIT` | отрицательный limit |
| `INVALID_OFFSET` | отрицательный offset |
| `INVALID_TEMPORAL_VALUE` | дата/время не в принятом системном формате |
| `ZERO_DATE` | передана нулевая дата (`0000-00-00`) |
| `NUMERIC_PART_OUT_OF_RANGE` | значение `month`/`day` вне допустимого диапазона |
| `DTO_SCHEMA_MISMATCH` | зарегистрированный DTO не соответствует схеме таблицы |
| `DTO_NOT_REGISTERED` | объектный метод на таблице без привязанного DTO |
| `INVALID_ENUM_VALUE` | хранимое значение не является кейсом enum из DTO |
| `DTO_HYDRATION_FAILED` | хранимое значение нельзя гидрировать в DTO |
