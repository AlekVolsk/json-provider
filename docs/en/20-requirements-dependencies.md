# Requirements and dependencies

## Runtime requirements

- **PHP 8.4 or newer.**
- Required bundled extensions (part of a standard PHP build):
  - `ext-json` — encoding and decoding NDJSON records;
  - `ext-phar` — reading and writing backup `.tar` archives;
  - `ext-zlib` — gzip compression of backups (`.tar.gz`).

These extensions ship with virtually every PHP distribution, so no extra installation is normally needed.

## Optional cache extensions

The cache layer is pluggable. The file and in-memory adapters need nothing extra; the following adapters each require their PHP extension and are therefore optional:

- `ext-apcu` — for `ApcuCache`;
- `ext-memcached` — for `MemcachedCache`;
- `ext-redis` — for `RedisCache`.

If an adapter's extension is missing, constructing that adapter raises the `EXTENSION_REQUIRED` error; choose another adapter (`InMemoryCache`, `NullCache`, or your own).

## Installation

`composer require alekvolsk/json-provider`
Beyond PHP and the bundled extensions above, the library has no Composer runtime dependencies.

## Development dependencies

For contributors, installing with dev dependencies (`composer install`) additionally pulls the tooling wired into the `Makefile`:

- `testo/testo` — the test runner and benchmark framework (`tests/Unit`, `tests/Bench`);
- `friendsofphp/php-cs-fixer` — the code-style fixer (`.php-cs-fixer.php`);
- `squizlabs/php_codesniffer` with `slevomat/coding-standard` — the code sniffer (`phpcs.xml`);
- `phpstan/phpstan` with `phpstan/phpstan-strict-rules` and `spaze/phpstan-disallowed-calls` — static analysis (`phpstan.neon`).

Run the whole suite with `make test`, or the matching `composer` scripts.
