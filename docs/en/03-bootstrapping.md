# Bootstrapping

`JsonDataProvider` is a per-path singleton.

## Open an existing storage

```php
use AV\JsonProvider\JsonDataProvider;

$db = JsonDataProvider::getInstance('/var/data/myapp');
```

The path must contain `information_schema.json`. The cache argument is honoured only on the first call for that path; later calls return the same instance.

## Create a new storage

```php
$db = JsonDataProvider::createDatabase('/var/data/myapp');
```

Creates the directory, an empty `information_schema.json` and an empty `meta.json`. Throws `StorageException` if the path already exists.

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
