# Bootstrapping

`JsonDataProvider` is a per-path singleton.

## Open an existing storage

```php
use AV\JsonProvider\JsonDataProvider;

$db = JsonDataProvider::getInstance('/var/data/myapp');
```

The storage must already exist at that path (the directory holds `information_schema.json`); `getInstance()` itself verifies nothing — a missing storage surfaces as an exception on the first operation. The cache argument is honoured only on the first call for that path; later calls return the same instance.

## Create a new storage

```php
$db = JsonDataProvider::createDatabase('/var/data/myapp');
```

Creates the directory, an empty `information_schema.json` and an empty `meta.json`. Throws `JsonProviderTableException` if the path already exists.

## Probe for existence

```php
if (!JsonDataProvider::exists('/var/data/myapp')) {
    JsonDataProvider::createDatabase('/var/data/myapp');
}
```

## Pass a cache adapter

```php
use AV\JsonProvider\Cache\ApcuCache;

$db = JsonDataProvider::getInstance('/var/data/myapp', new ApcuCache(ttl: 60));
```

See [Caching](16-caching.md).

## Pass a PSR-3 logger

```php
$db = JsonDataProvider::getInstance('/var/data/myapp', $cache, $psrLogger);
```

The logger is optional. The provider reports validator findings and runtime degradations to it on the unified severity scale — see [Integrity](14-integrity.md). Both parameters (`$cache`, `$logger`) apply only when the instance is created: a live singleton keeps the ones it was built with.
