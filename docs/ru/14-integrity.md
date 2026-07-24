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
| `pk_duplicate` | error | один id хранится в двух и более записях (id и строки — в контексте) |
| `rename_incomplete` | error | в meta.json остался маркер `_pendingRename` — `renameTable` не докатился |
| `fk_backing_index_missing` | warning | у cascade/restrict-связи нет покрывающего backing-индекса на FK-колонке ребёнка (legacy-схема) |
| `fk_backing_index_orphaned` | warning | служебный `_fk_`-индекс не является backing'ом ни одной пробующей связи (мёртвый вес) |
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
| `orphan_db_entry` | удалить файл; каталог удаляется целиком только если все файлы в нём пусты — непустой сирота требует ручного решения |
| `table_file_missing` | создать пустой файл данных, недостающие файлы индексов и запись в мете (окно краша `createTable`); потерянные данные не выдумываются |
| `pk_duplicate` | **не чинится**: помечается `repairError` — дубликаты PK разрешаются вручную |
| `rename_incomplete` | докатить/откатить `renameTable` по фактическому состоянию схемы (см. ниже) |
| `fk_backing_index_missing` | переиспользовать покрывающий одноколоночный пользовательский индекс либо построить служебный `_fk_<колонка>` из текущих данных и проставить `backingIndex` связи (структурная починка, данные не трогаются) |
| `fk_backing_index_orphaned` | удалить служебный индекс из схемы и его файл |

Сверка `rename_incomplete` выполняется **до** пер-issue-ремонта (иначе полупереименованная таблица чинилась бы как `table_file_missing` пустыми файлами под новым именем): если в схеме уже новое имя — мета и файловая система докатываются под него (rename каталога и файла данных идемпотентны); если в схеме ещё старое — переименование не зафиксировано, файловая система не тронута, снимается только маркер. Сверку делает только полный `repair()`: одиночный `repairTable()` держит лок лишь одного имени и потому честно отказывается (`repairError`) — как и запись в затронутую таблицу (`RENAME_INCOMPLETE`-исключение), пока маркер жив. `orphan_index_file`-ремонт удаляет только файлы вида `*.index.ndjson` либо пустые: непустой файл с другим именем может оказаться зависшими данными краша rename и требует ручного (или reconcile-) решения.

Отдельное свойство слоя чтения: NDJSON-строка, которую `json_decode` не парсит (битый JSON, невалидный UTF-8), при чтении молча пропускается. Валидатор увидит это лишь как `meta_line_count_drift`, а его ремонт зафиксирует новый счётчик — то есть узаконит потерю. Если целостность каждой строки критична, сверяйте `lineCount` меты с физическим числом строк файла внешним контуром до ремонта.

При нормализации записей (`record_key_order`, `optimizeTable`) отсутствующие в записи колонки заполняются дефолтом типа (`''`/`0`/`0.0`/`false`, `null` для nullable) — тем же значением, что записал бы `migrateColumns`, поэтому ремонт упавшей миграции сходится к её целевому состоянию, а не подкладывает `null` в not-null-колонку. Присутствующий в записи `null` остаётся `null`.

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
