# Целостность — validate и repair

В провайдере встроен сервис целостности. `validate*` — read-only; `repair*` — мутирующий.

## Шкала severity

Единая шкала для отчёта валидатора и опционального PSR-3-логгера (`IssueSeverityEnum`):

| Уровень | Критерий | PSR-3 |
| - | - | - |
| `critical` | работа с базой невозможна — падает чтение или запись даже на уровне full scan | `critical` |
| `error` | сломана структура схемного уровня, но full scan-чтения и записи живы | `error` |
| `warning` | связи или типизация данных не соответствуют схеме | `warning` |
| `info` | наблюдение по данным без задействования схемы (потерянные FK при `noAction`, успешная оптимизация) | `info` |

`IssueSeverityEnum::psrLevel()` возвращает уровень PSR-3, `rank()` — числовой ранг для сортировки (critical=0 … info=3). Отчёт (`IntegrityReport::$issues`) отсортирован critical-first; внутри одного уровня сохраняется порядок обнаружения. `hasErrors()` истинен для `error` **и** `critical`.

Если при создании инстанса передан PSR-3-логгер (`JsonDataProvider::getInstance($path, $cache, $logger)`), каждая находка прогона валидации логируется ровно один раз на уровне своего severity. Рантайм-деградации журналируются по той же шкале: тихий откат индексных чтений к full scan (byteSize-рассинхрон, формат ниже текущего) — `info`, бросок `JsonProviderServiceException` — `error`, пропуск нечитаемых строк при restore — `warning`, деградация кэш-бэкенда — `warning` (логгер кэш-адаптера, см. [16-caching.md](16-caching.md)).

## Validate

```php
$report = $db->validateTable('products');
$report = $db->validate();   // вся БД
```

`validate()` проверяет каждую таблицу и корень БД. Что репортится:

| Категория | Severity | Значение |
| - | - | - |
| `table_file_missing` | critical | файл данных объявленной таблицы отсутствует |
| `meta_entry_missing` | critical | в meta.json нет записи для объявленной таблицы (insert невозможен) |
| `meta_entry_corrupt` | critical | запись меты повреждена (не объект, счётчик не int) |
| `index_file_missing` | error | файл индекса, объявленного в схеме, отсутствует |
| `index_file_corrupt` | error | файл индекса не читается |
| `index_drift` | error | индекс не соответствует данным (count/keys/dangling line refs) |
| `index_unreliable` | error | структурная порча v2-индекса (permutation-нарушение) |
| `meta_line_count_drift` | error | meta.lineCount расходится с фактическим числом строк |
| `meta_last_id_drift` | error | meta.lastInsertedId меньше `max(id)` существующих записей |
| `pk_duplicate` | error | один id хранится в двух и более записях (id и строки — в контексте) |
| `rename_incomplete` | error | в meta.json остался маркер `_pendingRename` — `renameTable` не докатился |
| `fk_backing_index_missing` | error | у cascade/restrict-связи нет покрывающего backing-индекса на FK-колонке ребёнка |
| `repair_failed` | error | попытка ремонта бросила исключение или отказалась |
| `broken_record` | warning | непарсимая NDJSON-строка (физический номер строки и сырой текст — в контексте); report-only |
| `present_null` | warning | явный `null` в non-nullable колонке (колонка и строка — в контексте); report-only |
| `unique_duplicate` | warning | хранимые данные нарушают объявленный unique (имя ограничения и строки — в контексте); report-only |
| `fk_orphan` | warning / info | FK-значение ребёнка без соответствующего родительского ключа: `warning`, когда у связи объявлен enforce-`onDelete` (cascade/restrict/setNull — enforcement был обойдён), `info` при `onDelete: noAction` (норма-факт данных); severity считается по `onDelete`, `onUpdate` на неё не влияет. Также репортится связь на несуществующую таблицу/колонку и типовой рассинхрон сторон (достижимо только ручной правкой схемы); report-only |
| `orphan_index_file` | warning | файл в подкаталоге таблицы не объявлен в схеме |
| `record_key_order` | warning | в одной или нескольких записях порядок/набор ключей отличается от схемы |
| `meta_orphan_entry` | warning | meta.json содержит запись для таблицы вне схемы |
| `orphan_db_entry` | warning | в корне БД лежит файл или каталог вне схемы |
| `fk_backing_index_orphaned` | warning | служебный `_fk_`-индекс не является backing'ом ни одной пробующей связи (мёртвый вес) |
| `index_format_outdated` | info | индексы в пре-v2-формате; чтения деградируют в full scan до ближайшей записи/repair |
| `table_optimized` | info | таблица отсортирована по id и переиндексирована (только из repair) |

Проверки `fk_orphan` — уровня всей БД (нужны данные обеих сторон связи), выполняются только в `validate()`; `unique_duplicate`, `present_null` и `broken_record` — потабличные, их видит и `validateTable()`. Сравнение FK-значений типострогое — той же канонической кодировкой `keyPart`, которой пользуются unique-проверки и каскадный движок.

Валидатор **не** отмечает «записи не отсортированы по id» — это предпочтение ремонта, не контракт. Если хотите отсортированную раскладку — зовите `optimizeTable()`.

## Repair

```php
$report = $db->repairTable('products');
$report = $db->repair();             // вся БД
```

`repair*` запускает валидатор, пытается починить каждое actionable-issue, завершает каждую затронутую таблицу проходом `optimizeTable`. Репэйрер **никогда не выбрасывает исключение наружу** — провалы фиксируются внутри issue-объектов отчёта:

```php
foreach ($report->issuesByCategory(IssueCategoryEnum::INDEX_DRIFT) as $i) {
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
| `index_unreliable` | перестроить из данных, проштамповать формат v2 |
| `index_format_outdated` | перестроить все индексы таблицы, проштамповать v2 |
| `orphan_index_file` | удалить файл |
| `record_key_order` | перезаписать записи в каноническом порядке, переиндекс (отказ при непарсимых строках — см. ниже) |
| `meta_entry_missing` | заново завести запись, вычислить lineCount/lastId |
| `meta_entry_corrupt` | снести битую запись и завести заново из данных (как missing) |
| `meta_line_count_drift` | выставить фактическое число строк |
| `meta_last_id_drift` | выставить `max(id)` существующих записей |
| `meta_orphan_entry` | удалить запись из меты |
| `orphan_db_entry` | удалить файл; каталог удаляется целиком только если все файлы в нём пусты — непустой сирота требует ручного решения; каталог с заглавными буквами в имени провайдер не создаёт и не трогает — только вручную |
| `table_file_missing` | создать пустой файл данных, недостающие файлы индексов и запись в мете (окно краша `createTable`); потерянные данные не выдумываются |
| `pk_duplicate` | **не чинится**: помечается `repairError` — дубликаты PK разрешаются вручную |
| `rename_incomplete` | докатить/откатить `renameTable` по фактическому состоянию схемы (см. ниже) |
| `fk_backing_index_missing` | переиспользовать покрывающий одноколоночный пользовательский индекс либо построить служебный `_fk_<колонка>` из текущих данных и проставить `backingIndex` связи (структурная починка, данные не трогаются) |
| `fk_backing_index_orphaned` | удалить служебный индекс из схемы и его файл |
| `broken_record`, `present_null`, `fk_orphan`, `unique_duplicate` | **report-only, не трогаются**: repair чинит структуры, а не данные — он никогда не карантинит, не переписывает и не удаляет пользовательские записи ради исчезновения находки |

Сверка `rename_incomplete` выполняется **до** пер-issue-ремонта (иначе полупереименованная таблица чинилась бы как `table_file_missing` пустыми файлами под новым именем): если в схеме уже новое имя — мета и файловая система докатываются под него (rename каталога и файла данных идемпотентны); если в схеме ещё старое — переименование не зафиксировано, файловая система не тронута, снимается только маркер. Сверку делает только полный `repair()`: одиночный `repairTable()` держит лок лишь одного имени и потому честно отказывается (`repairError`) — как и запись в затронутую таблицу (`RenameIncomplete`-исключение), пока маркер жив. `orphan_index_file`-ремонт удаляет только файлы вида `*.index.ndjson` либо пустые: непустой файл с другим именем может оказаться зависшими данными краша rename и требует ручного (или reconcile-) решения.

### Защита от потери непарсимых строк

NDJSON-строку, которую `json_decode` не парсит, слой чтения молча пропускает — но валидатор показывает каждую такую строку находкой `broken_record` (физический номер строки и сырой текст в `context`). Любая полная перезапись файла циклом «read → write» такую строку уничтожила бы, поэтому `optimizeTable()` и ремонт `record_key_order` **отказываются** перезаписывать файл, в котором есть непарсимые строки (`REPAIR_FAILED` / `repairError` с числом строк): сначала разберите их вручную — почините текст строки в файле либо осознанно удалите её.

При нормализации записей (`record_key_order`, `optimizeTable`, restore) отсутствующие в записи колонки заполняются дефолтом типа (`''`/`0`/`0.0`/`false`, `null` для nullable) — тем же значением, что записал бы `migrateColumns`, поэтому ремонт упавшей миграции сходится к её целевому состоянию, а не подкладывает `null` в not-null-колонку. Тот же типовой back-fill выполняет каждый путь записи: `insert`, самолечение таблицы, схемные перезаписи (`migrateColumns`, `reorderColumns`, `renameColumn`, `truncate`) и многотабличная перезапись `update`/`delete` FK-движком. Non-nullable колонку без нулевого дефолта (temporal) не выдумывает никто: операция падает `JsonProviderDataException` — для FK-движка это фаза плана, диск остаётся нетронутым. Присутствующий в записи `null` остаётся `null` — и виден валидатору как `present_null`, пока данные не поправят.

## Рендеринг отчёта

```php
echo $report->format();
```

Plain-text, английский, фиксированная ширина. По одной строке на issue с тегом severity, категорией, контекстом таблицы и статусом ремонта; строки идут critical-first.

```php
$report->hasErrors();      // true при error И critical
$report->hasWarnings();
$report->issuesBySeverity(IssueSeverityEnum::CRITICAL);
$report->issuesByCategory(IssueCategoryEnum::META_LINE_COUNT_DRIFT);
```
