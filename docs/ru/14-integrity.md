# Целостность — validate и repair

В провайдере встроен сервис целостности. `validate*` — read-only; `repair*` — мутирующий.

## Validate

```php
$report = $db->validateTable('products');
$report = $db->validate();   // вся БД

if ($report->hasErrors()) {
    error_log($report->format());
}
```

`validate()` проверяет каждую таблицу и корень БД. Что репортится:

| Категория | Severity | Значение |
| - | - | - |
| `table_file_missing` | error | файл данных объявленной таблицы отсутствует |
| `index_file_missing` | error | файл индекса, объявленного в схеме, отсутствует |
| `index_file_corrupt` | error | файл индекса не читается |
| `index_drift` | error | индекс не соответствует данным (count/keys/dangling line refs) |
| `orphan_index_file` | warning | файл в подкаталоге таблицы не объявлен в схеме |
| `record_key_order` | warning | в одной или нескольких записях порядок/набор ключей отличается от схемы |
| `meta_entry_missing` | error | в meta.json нет записи для объявленной таблицы |
| `meta_line_count_drift` | error | meta.lineCount расходится с фактическим числом строк |
| `meta_last_id_drift` | error | meta.lastInsertedId меньше `max(id)` существующих записей |
| `meta_orphan_entry` | warning | meta.json содержит запись для таблицы вне схемы |
| `orphan_db_entry` | warning | в корне БД лежит файл или каталог вне схемы |
| `table_optimized` | info | таблица отсортирована по id и переиндексирована (только из repair) |
| `repair_failed` | error | попытка ремонта бросила исключение |

Валидатор **не** отмечает «записи не отсортированы по id» — это предпочтение ремонта, не контракт. Если хотите отсортированную раскладку — зовите `optimizeTable()`.

## Repair

```php
$report = $db->repairTable('products');
$report = $db->repair();             // вся БД
```

`repair*` запускает валидатор, пытается починить каждое actionable-issue, завершает каждую затронутую таблицу проходом `optimizeTable`. Репэйрер **никогда не выбрасывает исключение наружу** — провалы фиксируются внутри issue-объектов отчёта:

```php
foreach ($report->issuesByCategory(IssueCategory::INDEX_DRIFT) as $i) {
    if (!$i->repaired) {
        error_log('repair failed: ' . ($i->repairError ?? 'unknown'));
    }
}
```

Что чинится:

| Issue | Действие |
| - | - |
| `index_file_missing` | пересоздать файл, перестроить из данных |
| `index_file_corrupt` | перестроить из данных |
| `index_drift` | перестроить из данных |
| `orphan_index_file` | удалить файл |
| `record_key_order` | перезаписать записи в каноническом порядке, переиндекс |
| `meta_entry_missing` | заново завести запись, вычислить lineCount/lastId |
| `meta_line_count_drift` | выставить фактическое число строк |
| `meta_last_id_drift` | выставить `max(id)` существующих записей |
| `meta_orphan_entry` | удалить запись из меты |
| `orphan_db_entry` | удалить файл или (если пуст) каталог |
| `table_file_missing` | не чинится автоматически (потеря данных); фиксируется как failure |

## Рендеринг отчёта

```php
echo $report->format();
```

Plain-text, английский, фиксированная ширина. По одной строке на issue с тегом severity, категорией, контекстом таблицы и статусом ремонта.

```php
$report->hasErrors();
$report->hasWarnings();
$report->issuesBySeverity(IssueSeverity::ERROR);
$report->issuesByCategory(IssueCategory::META_LINE_COUNT_DRIFT);
```
