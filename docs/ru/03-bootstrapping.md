# Инициализация

`JsonDataProvider` — синглтон по пути.

## Открыть существующее хранилище

```php
use AV\JsonProvider\JsonDataProvider;

$db = JsonDataProvider::getInstance('/var/data/myapp');
```

В пути должен быть `information_schema.json`. Аргумент кеша учитывается только при первом вызове для этого пути; последующие вызовы возвращают тот же экземпляр.

## Создать новое хранилище

```php
$db = JsonDataProvider::createDatabase('/var/data/myapp');
```

Создаёт каталог, пустой `information_schema.json` и пустой `meta.json`. Бросает `StorageException`, если путь уже существует.

## Проверить наличие

```php
if (!JsonDataProvider::exists('/var/data/myapp')) {
    JsonDataProvider::createDatabase('/var/data/myapp');
}
```

## Передать кеш-адаптер

```php
use AV\JsonProvider\Cache\ApcuCache;

$db = JsonDataProvider::getInstance('/var/data/myapp', new ApcuCache(ttl: 60));
```

См. [Кеширование](16-caching.md).
