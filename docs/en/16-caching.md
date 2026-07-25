# Caching

By default the provider uses `NullCache` — every read goes to disk.

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

Only APCu carries an explicit extension check in its constructor (`ExtensionRequired`); for Redis/Memcached the client type hint itself requires the extension — without it `new RedisCache(...)`/`new MemcachedCache(...)` fails with a `TypeError` before the constructor runs.

## Key format: namespace and version tag

Every key has the shape `jdp:<format version>:<db path hash>:<table>:<tag>`:

- the **path hash** (16 hex of sha1 over the directory's `realpath`) isolates databases sharing one backend pool: two databases with a `users` table can never read each other's rows;
- the **format version** (`JsonDataProvider::CACHE_FORMAT_VERSION`) retires keys of older formats on upgrade — they expire by TTL/eviction;
- the **tag** is `<meta lineCount>-<physical data file size>-<inode>`. It binds a cache entry to one committed state of the table: any write that changes the size or the row count — including a FOREIGN append straight into the ndjson file, bypassing the provider — moves the tag; and the inode kills the A-B-A class: every full provider rewrite goes through tmp+rename onto a **fresh** inode, so a delete+insert of the same byte length, a truncate with a re-import, a drop+recreate or a same-length value swap **through the provider from any process** can never reproduce an earlier tag and resurrect a warm entry of the old state. Every stale entry simply stops resolving — with no cross-process invalidation messages at all.

The row cache serves **only** full-scan reads and `count()`; index-driven selects always go to disk. Write paths never read the cache (it is never the base of a rewrite) and publish the fresh entry under the tag of the state they just committed.

**Residual window** (documented, accepted): a FOREIGN **in-place** file edit that keeps the byte size (a same-length value swap by an external tool, without a rename) moves no tag component — warm entries live until TTL/eviction. The manual exits are `invalidateCache('table')` (drops the current tag's entry) and `flushDb()`. Any write the provider itself performs — from whatever process — always moves the tag.

`getInstance()` against an already existing singleton silently ignores the passed cache adapter — the first call's adapter stays.

## flushDb — scoped flush

`JsonDataProvider::flushDb()` removes **only the current database's keys** from the backend (by the namespace prefix) — never the whole server/pool:

- Redis — cursor-based `SCAN MATCH <prefix>*` with `UNLINK` (a `DEL` fallback for Redis < 4.0);
- APCu — an `APCUIterator` over the prefix;
- InMemory — dropping keys by `str_starts_with`;
- Memcached — the protocol cannot enumerate keys, so the adapter keeps a per-database-prefix GENERATION (a counter key `<prefix>__gen`, spliced into every physical key); `flushDb` increments the counter — keys of the previous generation become unreachable and expire by TTL. Two caveats: (a) another worker sees the bump only when it re-instantiates the adapter or its local generation memo is cold; (b) LRU eviction of the `__gen` counter resets the generation. Data correctness is carried by the version tag, not by flushDb.

## Fault tolerance — one degradation policy

The `CacheInterface` contract for **every** adapter, custom ones included: any backend error degrades `get()` to `null` (a miss — the provider falls back to disk) and turns the mutations (`set`/`invalidate`/`flushDb`) into silent no-ops. A cache outage never fails a database operation and never serves wrong data. Every bundled adapter takes an optional PSR-3 logger in its own constructor and reports degradations at `warning` level:

```php
$db = JsonDataProvider::getInstance(
    '/path',
    new RedisCache($redis, ttl: 300, logger: $psrLogger),
);
```

## InMemoryCache: TTL and eviction

`new InMemoryCache(ttl: 0, maxEntries: 1000)` — TTL in seconds (0 = forever, the historical default), an entry cap with FIFO eviction in insertion order (0 = unlimited). Expired entries are removed lazily on `get()`. Cross-process coherence is the job of the version-tagged keys, not of this adapter.

## Custom adapter

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

A custom adapter must honor the degradation policy (above) and the `flushDb` semantics — remove only the keys under the given prefix.
