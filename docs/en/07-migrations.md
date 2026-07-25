# Migrations — defining the schema

The provider is driven from your own migrations, and changing the structure — creating tables — is just one kind of them. There is no bundled migration runner: how migrations are tracked and what triggers them (a CLI command, a deploy step, a first-request bootstrap) is left to the host project. Below is the shape of such a structural migration.

A thin access class hands out the shared `JsonDataProvider`, creating the storage on first use:

```php
use AV\JsonProvider\JsonDataProvider;

final class Db
{
    private static JsonDataProvider | null $instance = null;

    public static function provider(): JsonDataProvider
    {
        if (self::$instance === null) {
            $path = '/var/data/myapp';
            self::$instance = JsonDataProvider::exists($path)
                ? JsonDataProvider::getInstance($path)
                : JsonDataProvider::createDatabase($path);
        }

        return self::$instance;
    }
}
```

The migration declares the structure — two tables. The extra keys (a unique constraint and an index) live on `users` only; `posts` gets by on its primary key alone:

```php
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;

final class InitialMigration
{
    /** @return list<TableSchema> */
    public function tables(): array
    {
        return [
            TableSchema::create(
                name: 'users',
                columns: [
                    'email'    => ColumnTypes::STRING,
                    'name'     => ColumnTypes::STRING,
                    'isActive' => ColumnTypes::BOOL,
                ],
                uniqueConstraints: [
                    new UniqueConstraint('uq_users_email', ['email']),
                ],
                indexes: [
                    new IndexSchema('idx_users_email', [new IndexFieldSchema('email', SortDirectionEnum::ASC)]),
                ],
                tableComment: 'User accounts',
                columnComment: [
                    'email'    => 'Email address, unique',
                    'name'     => 'Display name',
                    'isActive' => 'Whether the account is active',
                ],
            ),
            TableSchema::create(
                name: 'posts',
                columns: [
                    'userId' => ColumnTypes::INT,
                    'title'  => ColumnTypes::STRING,
                    'body'   => ColumnTypes::STRING_NULLABLE,
                ],
                tableComment: 'User posts',
                columnComment: [
                    'userId' => 'Author of the post (users.id)',
                    'title'  => 'Title',
                    'body'   => 'Post body (optional)',
                ],
            ),
        ];
    }
}
```

Running a migration is a walk over its steps, and here a fork in failure handling matters.

**Fail on the first error** is the right choice when steps depend on one another and a partial apply is unacceptable: the exception propagates and aborts the whole run.

```php
$db = Db::provider();

foreach ((new InitialMigration())->tables() as $schema) {
    $db->createTable($schema);   // any failure aborts the whole run
}
```

**Skip the failing step and log it** is the right choice when steps are independent: there is no reason to sink the other tables because of one. Catch the error, log it, and carry on; `TableAlreadyExists` also makes a repeated run idempotent.

```php
use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

$db = Db::provider();

foreach ((new InitialMigration())->tables() as $schema) {
    try {
        $db->createTable($schema);
    } catch (JsonProviderException $e) {
        if ($e->error === JsonProviderErrorEn::TableAlreadyExists) {
            continue;   // already applied — a repeated run is safe
        }

        error_log("migration: table \"{$schema->name}\" skipped — " . $e->getMessage());
    }
}
```

Later migrations rarely just create tables. To evolve or remove an existing one — add/drop/reorder columns, or drop the table outright — see [Schema mutations](12-schema-mutations.md); `hasTable()` / `columnNames()` help keep such steps idempotent.

## Upgrading the index format

The index key format is versioned per table (`indexFormat` in `meta.json`, see [Indexes](06-indexes.md)). No active migration is required after a package update: tables with the old format are read via full scans, and the first write into a table rebuilds its indexes and stamps the new format. To force-upgrade the whole database, run once:

```php
foreach ($db->tableNames() as $table) {
    $db->table($table)->rebuildAllIndexes();
}
```

Rolling back to a package version with the old format is safe only after the same `rebuildAllIndexes()` pass executed by that old version.

## Upgrading time/timez storage (verbatim)

Starting with the version where `time`/`timez` switched to **verbatim** storage (wall-clock, no timezone shift; `date` was already verbatim, `datetime`/`datetimez` stay in UTC — see [Schema model → Temporal types](04-schema-model.md#temporal-types-and-timezones)), the on-disk format of these columns **changed**. Unlike indexes, there is no automatic self-healing: the old values were shifted into UTC, so without a migration a read returns the shifted time and a local-time query will not match the index.

The upgrade is a one-off data rewrite of the `time`/`timez` columns **plus a mandatory rebuild of any index on them** (the old on-disk form disagrees with the new condition encoding):

- **Fixed-offset deploy zone** (no DST): add that zone's local offset to each stored value — the conversion is exact.
- **DST deploy zone**: the pre-shift `time`/`timez` values are per-row ambiguous (the offset in effect at write time is unknown). The reliable path is exporting the data and re-importing under a controlled zone; an automatic shift here is best-effort.

After the data rewrite, run `rebuildAllIndexes()` (or `optimizeTable()`) on the affected tables. Under a UTC process there was no shift — no migration is needed. Record the version up to which the format was UTC-shifted, to tell migrated databases from un-migrated ones.
