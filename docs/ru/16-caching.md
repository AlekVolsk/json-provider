# Кеширование

По умолчанию используется `NullCache` — каждое чтение идёт на диск.

```php
use AV\JsonProvider\Cache\ApcuCache;
use AV\JsonProvider\Cache\MemcachedCache;
use AV\JsonProvider\Cache\RedisCache;
use AV\JsonProvider\Cache\InMemoryCache;

$db = JsonDataProvider::getInstance('/path', new ApcuCache(ttl: 60));

$memcached = new \Memcached();
$memcached->addServer('127.0.0.1', 11211);
$db = JsonDataProvider::getInstance('/path', new MemcachedCache($memcached, ttl: 120));

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);
$db = JsonDataProvider::getInstance('/path', new RedisCache($redis, ttl: 300));

$db = JsonDataProvider::getInstance('/path', new InMemoryCache());
```

Явную проверку расширения в конструкторе несёт только APCu (`ExtensionRequired`); для Redis/Memcached расширение требует сам тайп-хинт клиента — без него `new RedisCache(...)`/`new MemcachedCache(...)` падает `TypeError` ещё до конструктора.

## Формат ключей: неймспейс и version-tag

Каждый ключ имеет вид `jdp:<версия формата>:<хэш пути БД>:<таблица>:<тег>`:

- **хэш пути** (16 hex от sha1 `realpath` каталога) изолирует БД, делящие один бэкенд-пул: две базы с таблицей `users` никогда не прочитают записи друг друга;
- **версия формата** (`JsonDataProvider::CACHE_FORMAT_VERSION`) выводит из оборота ключи, записанные в другом формате (другой версией пакета), — они протухают по TTL/эвикции;
- **тег** = `<lineCount из меты>-<физический размер файла данных>-<inode>`. Тег привязывает запись кэша к одному зафиксированному состоянию таблицы: любая запись, меняющая размер или счётчик строк — включая **чужую** дозапись прямо в ndjson, мимо провайдера, — сдвигает тег; а inode закрывает A-B-A-класс: каждая полная перезапись провайдера идёт через tmp+rename и ложится на **новый** inode, поэтому delete+insert той же длины, truncate с повторным импортом, drop+recreate или замена значения той же длины **через провайдер из любого процесса** никогда не воспроизводят прежний тег и не воскрешают тёплую запись старого состояния. Все устаревшие записи просто перестают резолвиться — без каких-либо инвалидационных сообщений между процессами.

Row-кэш обслуживает **только** full-scan-чтения и `count()`; индексные выборки всегда идут на диск. Пишущие пути кэш не читают никогда (он не бывает базой перезаписи) и публикуют свежую запись под тегом только что зафиксированного состояния.

**Остаточное окно** (задокументировано, принято): **чужая** правка файла **на месте**, не меняющая его размер (in-place-подмена значения той же длины сторонним инструментом, без rename), не сдвигает ни одну компоненту тега — тёплые записи доживают до TTL/эвикции. Ручной выход — `invalidateCache('table')` (сносит запись текущего тега) либо `flushDb()`. Любая запись самого провайдера — из какого угодно процесса — тег сдвигает всегда.

При `getInstance()` по уже существующему синглтону переданный кэш-адаптер игнорируется — используется адаптер первого вызова.

## flushDb — скоуп-очистка

`JsonDataProvider::flushDb()` удаляет из бэкенда **только ключи текущей БД** (по префиксу неймспейса) — никогда весь сервер/пул:

- Redis — курсорный `SCAN MATCH <префикс>*` с `UNLINK` (fallback `DEL` для Redis < 4.0);
- APCu — `APCUIterator` по префиксу;
- InMemory — снос ключей по `str_starts_with`;
- Memcached — протокол не умеет перечислять ключи, поэтому адаптер ведёт **поколение** на префикс БД (счётчик-ключ `<префикс>__gen`, поколение вклеивается в физический ключ); `flushDb` инкрементирует счётчик — ключи прежнего поколения становятся недостижимыми и протухают по TTL. Два caveat'а: (а) другой воркер видит смену поколения только при переинстанцировании адаптера либо холодном мемо; (б) LRU-вытеснение счётчика `__gen` сбрасывает поколение. Корректность данных держит version-tag, а не flushDb.

## Отказоустойчивость — единая политика деградации

Контракт `CacheInterface` для **всех** адаптеров, включая кастомные: любая ошибка бэкенда — `get()` → `null` (промах, провайдер идёт на диск), мутации (`set`/`invalidate`/`flushDb`) — тихие no-op. Недоступность кэша никогда не роняет операцию БД и не отдаёт неверные данные. Каждый штатный адаптер принимает опциональный PSR-3-логгер в собственном конструкторе и репортит деградацию уровнем `warning`:

```php
$db = JsonDataProvider::getInstance(
    '/path',
    new RedisCache($redis, ttl: 300, logger: $psrLogger),
);
```

## InMemoryCache: TTL и эвикция

`new InMemoryCache(ttl: 0, maxEntries: 1000)` — TTL в секундах (0 = вечно, по умолчанию), лимит записей с FIFO-вытеснением по порядку вставки (0 = без лимита). Просроченные записи удаляются лениво при `get()`. Межпроцессную когерентность обеспечивает не адаптер, а version-tag ключей.

## Свой адаптер

```php
use AV\JsonProvider\Cache\CacheInterface;

final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $dir) {}
    public function get(string $key): array | null { /* ... */ }
    public function set(string $key, array $records): void { /* ... */ }
    public function invalidate(string $key): void { /* ... */ }
    public function flushDb(string $keyPrefix): void { /* ... */ }
}

$db = JsonDataProvider::getInstance('/path', new FileCache('/tmp/cache'));
```

Кастомный адаптер обязан соблюдать политику деградации (см. выше) и семантику `flushDb` — удалять только ключи с переданным префиксом.
