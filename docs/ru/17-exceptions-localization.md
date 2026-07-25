# Исключения и локализация

Все исключения провайдера наследуются от `AV\JsonProvider\Exception\JsonProviderException` — абстрактного класса, который сам не бросается. Бросаются девять доменных классов: инженер выбирает гранулярность отлова тем, какой из них ловит.

| Класс | Домен |
| - | - |
| `JsonProviderSchemaException` | структура схемы, DDL-контракты, идентификаторы, контракт первичного ключа, миграции колонок |
| `JsonProviderTableException` | жизненный цикл таблицы и базы |
| `JsonProviderQueryException` | построение запроса и фильтра |
| `JsonProviderDataException` | значения и записи |
| `JsonProviderRelationException` | внешние ключи — объявление и исполнение |
| `JsonProviderMappingException` | привязка объекта записи к таблице |
| `JsonProviderServiceException` | резервные копии, целостность, мета, адаптеры кеша |
| `JsonProviderIoException` | файлы и JSON |
| `JsonProviderLockException` | блокировки |

## Разбор ошибки

```php
use AV\JsonProvider\Exception\JsonProviderDataException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

try {
    $db->table('users')->insertByArray(['email' => $existing]);
} catch (JsonProviderDataException $e) {
    $e->error;                  // кейс словаря — для match, без магических строк
    $e->getMessage();           // инвариантное сообщение (английское) для логов
    $e->getLocalizedMessage();  // сообщение в активной локали, для человека
    $e->getErrorKey();          // имя кейса строкой, для логов и метрик

    match ($e->error) {
        JsonProviderErrorEn::UniqueViolation => $this->reportDuplicate($e),
        default => throw $e,
    };
}
```

Идентификатор ситуации — кейс `JsonProviderErrorEn`, и он не зависит от выставленной локали: место броска всегда называет один и тот же enum, а локаль влияет только на текст.

## Сообщения

`getMessage()` фиксируется в момент создания исключения и остаётся английским — лог не должен зависеть от того, какую локаль выставило приложение. `getLocalizedMessage()` вычисляется на каждый вызов и следует активной локали; если локаль не знает кейса, возвращается английский текст целиком.

## Выбор локали

```php
use AV\JsonProvider\Exception\Locale\JsonProviderErrorRu;

$db->setLocale(JsonProviderErrorRu::TableNotFound);  // важен только класс, кейс любой
$db->resetLocale();                                  // вернуться к английскому
```

Локаль действует на процесс целиком, а не на отдельный инстанс провайдера.

## Своя локаль

Локаль — это один enum на язык, реализующий `LocaleInterface`. Имена кейсов совпадают с `JsonProviderErrorEn`, значение — шаблон сообщения с позиционными `%s`:

```php
use AV\JsonProvider\Exception\Locale\LocaleInterface;

enum JsonProviderErrorDe: string implements LocaleInterface
{
    case TableNotFound = 'Tabelle "%s" nicht im Schema gefunden';
    case UniqueViolation = 'Eindeutigkeitsverletzung in "%s" auf Feldern [%s]: %s';

    public static function translate(string $key, string ...$params): string
    {
        foreach (self::cases() as $case) {
            if ($case->name === $key) {
                return $params !== []
                    ? \sprintf($case->value, ...$params)
                    : $case->value;
            }
        }

        return $key;   // неизвестный кейс — провайдер возьмёт английский текст
    }
}

$db->setLocale(JsonProviderErrorDe::TableNotFound);
```

Класс локали может лежать в любом пространстве имён. Переводить весь словарь сразу не обязательно: непереведённый кейс рендерится по-английски целиком, а не смешивается с переведённым текстом в одном предложении.

Текст шаблона — человеческий язык: имён методов и внутренних сокращений в нём нет, поэтому переводить его может человек, не знающий устройства пакета. Дословно остаются только ключи JSON-файлов (`"tables"`, `"fields"`), допустимые значения (`noAction`, `cascade`, `setNull`, `restrict`, `asc`, `desc`), имена типов колонок, имена атрибутов и литералы языка.

## Логирование

Если провайдер создан с PSR-3-логгером, **каждое** исключение провайдера пишет себя в лог на уровне `error` в момент возникновения — ловить его для этого не нужно:

```php
$db = JsonDataProvider::getInstance($path, $cache, $logger);
```

В лог уходит инвариантное сообщение и контекст:

| Ключ контекста | Что в нём |
| - | - |
| `errorKey` | имя кейса — по нему фильтруются и считаются ошибки |
| `origin` | место броска, `файл(строка)` |
| `trace` | стек вызовов |

Пути в `origin` и `trace` обрезаны: всё до сегмента `/vendor` отбрасывается, поэтому в лог попадает место внутри пакета, а не раскладка машины.

## Словарь ситуаций

177 кейсов; имя кейса — идентификатор, английский текст лежит в самом кейсе (`JsonProviderErrorEn::TableNotFound->value`).
