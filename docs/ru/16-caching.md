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

Каждый адаптер проверяет наличие своего PHP-расширения в конструкторе и бросает `EXTENSION_REQUIRED`, если расширение отсутствует. Кеш инвалидируется автоматически при каждой `insert`, `update`, `delete`, а также при `optimizeTable`, `restore`, `repair`.

## Свой адаптер

```php
use AV\JsonProvider\Cache\CacheInterface;

final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $dir) {}
    public function get(string $key): array | null { /* ... */ }
    public function set(string $key, array $records): void { /* ... */ }
    public function invalidate(string $key): void { /* ... */ }
    public function flush(): void { /* ... */ }
}

$db = JsonDataProvider::getInstance('/path', new FileCache('/tmp/cache'));
```
