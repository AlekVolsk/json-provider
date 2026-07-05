# Требования и зависимости

## Требования к среде выполнения

- PHP 8.4 или новее.
- Обязательные встроенные расширения (входят в стандартную сборку PHP):
  - ext-json — кодирование и декодирование NDJSON-записей;
  - ext-phar — чтение и запись резервных .tar-архивов;
  - ext-zlib — gzip-сжатие бэкапов (.tar.gz).
Эти расширения поставляются практически с любым дистрибутивом PHP, поэтому обычно ничего доустанавливать не нужно.

## Опциональные расширения кеша

Слой кеширования подключаемый. Файловому и in-memory адаптерам ничего дополнительно не требуется; перечисленные ниже адаптеры требуют своего PHP-расширения и потому опциональны:

- ext-apcu — для ApcuCache;
- ext-memcached — для MemcachedCache;
- ext-redis — для RedisCache.

Если расширение адаптера отсутствует, конструирование этого адаптера бросает ошибку EXTENSION_REQUIRED; выберите другой адаптер (InMemoryCache, NullCache или свой собственный).

## Установка

`composer require alekvolsk/json-provider`
Кроме PHP и перечисленных выше встроенных расширений, у библиотеки нет runtime-зависимостей Composer.

## Зависимости для разработки

Для контрибьюторов установка с dev-зависимостями (composer install) дополнительно подтягивает инструментарий, подключённый в Makefile:

- testo/testo — раннер тестов и фреймворк бенчмарков (tests/Unit, tests/Bench);
- friendsofphp/php-cs-fixer — фиксер код-стайла (.php-cs-fixer.php);
- squizlabs/php_codesniffer вместе со slevomat/coding-standard — сниффер кода (phpcs.xml);
- phpstan/phpstan вместе с phpstan/phpstan-strict-rules и spaze/phpstan-disallowed-calls — статический анализ (phpstan.neon).

Запускайте весь набор через make test или соответствующие composer-скрипты.
