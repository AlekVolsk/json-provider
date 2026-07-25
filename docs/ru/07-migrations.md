# Миграции — определение схемы

Провайдер вызывается из ваших собственных миграций, и изменение структуры — создание таблиц — лишь один из их видов. Раннера миграций в комплекте нет: как их отслеживать и чем запускать (CLI-командой, шагом деплоя, бутстрапом первого запроса) — решает сам проект. Ниже — форма такой структурной миграции.

Тонкий класс доступа отдаёт общий экземпляр `JsonDataProvider`, создавая хранилище при первом обращении:

```php
use AV\JsonProvider\JsonDataProvider;

final class Db
{
    private static JsonDataProvider | null $instance = null;

    public static function provider(): JsonDataProvider
    {
        if (self::$instance === null) {
            $path = '/var/data/myapp';
            self::$instance = JsonDataProvider::exists($path)
                ? JsonDataProvider::getInstance($path)
                : JsonDataProvider::createDatabase($path);
        }

        return self::$instance;
    }
}
```

Миграция объявляет структуру — две таблицы. Дополнительные ключи (unique-ограничение и индекс) — только у `users`; `posts` обходится одним первичным ключом:

```php
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;

final class InitialMigration
{
    /** @return list<TableSchema> */
    public function tables(): array
    {
        return [
            TableSchema::create(
                name: 'users',
                columns: [
                    'email'    => ColumnTypes::STRING,
                    'name'     => ColumnTypes::STRING,
                    'isActive' => ColumnTypes::BOOL,
                ],
                uniqueConstraints: [
                    new UniqueConstraint('uq_users_email', ['email']),
                ],
                indexes: [
                    new IndexSchema('idx_users_email', [new IndexFieldSchema('email', SortDirectionEnum::ASC)]),
                ],
                tableComment: 'Учётные записи пользователей',
                columnComment: [
                    'email'    => 'Адрес электронной почты, уникальный',
                    'name'     => 'Отображаемое имя',
                    'isActive' => 'Активна ли учётная запись',
                ],
            ),
            TableSchema::create(
                name: 'posts',
                columns: [
                    'userId' => ColumnTypes::INT,
                    'title'  => ColumnTypes::STRING,
                    'body'   => ColumnTypes::STRING_NULLABLE,
                ],
                tableComment: 'Публикации пользователей',
                columnComment: [
                    'userId' => 'Автор публикации (users.id)',
                    'title'  => 'Заголовок',
                    'body'   => 'Тело публикации (необязательно)',
                ],
            ),
        ];
    }
}
```

Прогон миграции — это обход её шагов, и здесь важна развилка по обработке сбоя.

**Падать на первом же сбое** уместно, когда шаги зависят друг от друга и частичное применение недопустимо: исключение пробрасывается и обрывает весь прогон.

```php
$db = Db::provider();

foreach ((new InitialMigration())->tables() as $schema) {
    $db->createTable($schema);   // любой сбой прерывает весь прогон
}
```

**Пропускать сбойный шаг с логированием** уместно, когда шаги независимы: незачем ронять создание остальных таблиц из-за одной. Ошибку ловим, пишем в лог и продолжаем; а `TABLE_ALREADY_EXISTS` заодно делает повторный прогон идемпотентным.

```php
use AV\JsonProvider\Exception\StorageException;

$db = Db::provider();

foreach ((new InitialMigration())->tables() as $schema) {
    try {
        $db->createTable($schema);
    } catch (StorageException $e) {
        if ($e->getErrorKey() === 'TABLE_ALREADY_EXISTS') {
            continue;   // уже применено — повторный прогон безопасен
        }

        error_log("миграция: таблица «{$schema->name}» пропущена — " . $e->getMessage());
    }
}
```

Поздние миграции редко просто создают таблицы. Как изменить или удалить существующую — добавить/удалить/переупорядочить колонки или снести таблицу целиком — см. [Изменение схемы](12-schema-mutations.md); `hasTable()` / `columnNames()` помогают делать такие шаги идемпотентными.

## Апгрейд формата индексов

Формат ключей индексов версионируется per-table (`indexFormat` в `meta.json`, см. [Индексы](06-indexes.md)). После обновления пакета активной миграции не требуется: таблицы со старым форматом читаются full scan'ом, а первая запись в таблицу перестраивает её индексы и штампует новый формат. Принудительный апгрейд всей БД — однократный прогон:

```php
foreach ($db->tableNames() as $table) {
    $db->table($table)->rebuildAllIndexes();
}
```

Откат на версию пакета со старым форматом — только после такого же прогона `rebuildAllIndexes()` уже старой версией.

## Апгрейд хранения time/timez (verbatim)

Начиная с версии, где `time`/`timez` перешли на **verbatim**-хранение (wall-clock, без сдвига по TZ; `date` и раньше был verbatim, `datetime`/`datetimez` остаются в UTC — см. [Модель схемы → Типы даты/времени](04-schema-model.md#типы-датывремени-и-часовые-пояса)), формат этих колонок на диске **изменился**. В отличие от индексов, автоматического самолечения тут нет: старые значения были сдвинуты в UTC, и без миграции чтение вернёт сдвинутое время, а запрос по локальному времени не совпадёт с индексом.

Апгрейд — однократный data-rewrite `time`/`timez`-колонок **плюс обязательная перестройка индексов по ним** (старая on-disk форма рассинхронизирована с новым кодированием условий):

- **Зона деплоя с фиксированным оффсетом** (без DST): к каждому хранимому значению прибавить локальный оффсет этой зоны — преобразование точное.
- **Зона с DST**: досдвиговые `time`/`timez` неоднозначны per-row (какой оффсет действовал при записи — неизвестно). Надёжный путь — экспорт данных и реимпорт под контролируемой зоной; автоматический сдвиг здесь best-effort.

После data-rewrite обязательно `rebuildAllIndexes()` (или `optimizeTable()`) по затронутым таблицам. Под UTC-процессом сдвига не было — миграция не требуется. Зафиксируйте версию, до которой формат был UTC-сдвинутым, чтобы отличать мигрированные БД от немигрированных.
