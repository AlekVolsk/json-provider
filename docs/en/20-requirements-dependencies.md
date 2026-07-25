# Requirements and dependencies

## Runtime requirements

- **PHP 8.4 or newer.**
- A POSIX-compatible OS (Linux, macOS, BSD). The locking model is built on `flock`, so **Windows and network filesystems (NFS, SMB) are not supported** — the DB directory must live on a local filesystem.
- Required bundled extensions (part of a standard PHP build):
  - `ext-json` — encoding and decoding NDJSON records;
  - `ext-phar` — reading and writing backup `.tar` archives;
  - `ext-zlib` — gzip compression of backups (`.tar.gz`).

These extensions ship with virtually every PHP distribution, so no extra installation is normally needed.

## Optional extensions

Composer lists them under `suggest` — none of them is required:

- `ext-apcu` — the `ApcuCache` adapter;
- `ext-memcached` — the `MemcachedCache` adapter;
- `ext-redis` — the `RedisCache` adapter;
- `ext-intl` — the `ComparisonMode::Locale` string ordering (collator); without the extension the mode silently behaves as `Binary`, see [Query builder](08-query-builder.md).

The cache layer is pluggable: the bundled `NullCache` and `InMemoryCache` (and your own adapter) need nothing extra. A missing extension surfaces differently per adapter: `ApcuCache` checks for it explicitly and raises `ExtensionRequired`, while `RedisCache`/`MemcachedCache` take a `\Redis`/`\Memcached` client in their constructor and fail with a `TypeError` before it even runs — see [Caching](16-caching.md).

## Installation

`composer require alekvolsk/json-provider`

The single Composer runtime dependency is `psr/log` (^3) — the logger interface the provider and the cache adapters accept optionally. Everything else is PHP plus the bundled extensions above.

## Development dependencies

For contributors, installing with dev dependencies (`composer install`) additionally pulls the tooling wired into the `Makefile`:

- `testo/testo` — the test runner and benchmark framework (`tests/Unit`, `tests/Bench`);
- `friendsofphp/php-cs-fixer` — the code-style fixer (`.php-cs-fixer.php`);
- `squizlabs/php_codesniffer` with `slevomat/coding-standard` — the code sniffer (`phpcs.xml`);
- `phpstan/phpstan` with `phpstan/phpstan-strict-rules` and `spaze/phpstan-disallowed-calls` — static analysis (`phpstan.neon`).

Run the whole suite with `make test`, or the matching `composer` scripts.
