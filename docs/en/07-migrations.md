# Migrations — defining the schema and versioned migrations

## Defining the schema

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

Later migrations rarely just create tables. To evolve or remove an existing one — add/drop/reorder columns, or drop the table outright — see [Schema mutations](12-schema-mutations.md); `hasTable()` / `columnNames()` help keep such steps idempotent, and `diffTable()` shows what separates a table from a desired schema.

## Versioned migrations

A new library version may bring a new storage format generation (see [Storage format](23-storage-format.md)). A versioned migration touches neither your data nor your schema: it brings the service part of the database — the format manifest, the table stamps, the indexes — to the engine's generation. It runs explicitly, once per database.

### Status and migration

```php
$status = $db->storageStatus();

if (!$status->isCurrent()) {
    $report = $db->migrateStorage();
}
```

`storageStatus()` only reads and takes no write lock. It reports:

- the database generation and the engine generation (`generation`, `engineGeneration`);
- the manifest flags (`compat`, `roCompat`, `incompat`) and the `readOnly` sign;
- the steps a migration would run (`pendingSteps`, for example `['1->2']`);
- the tables it would rebuild and stamp or build derived files for (`pendingTables`);
- the `compat` flags of the engine's generation the manifest does not set yet (`pendingFeatures`).

The status reads the disk, not the instance's memory, so it sees a migration another process ran.

`migrateStorage(?int $toGeneration = null)` brings the database to the engine's generation (or to the given one, no higher) and makes every table fresh:

- it runs under the database EX lock and EX locks on all tables, like `repair()` — an operation for deploy time, not for live traffic; it costs as much as `rebuildAllIndexes()` over the whole database;
- generations are climbed one step at a time (`1->2`, later `2->3` and so on); each step is idempotent and writes the manifest as its last act. A crash inside a step leaves the database in the previous generation, and a re-run finishes the step;
- a table whose data file holds lines that are not records is not touched at all: a rewrite would lose such a line, and indexes built around it cannot be trusted. It is listed in the report's `skippedTables` and keeps working as before. The exception is a torn, never-acknowledged tail left by a crashed append: the repair drops it, as any write does;
- migration only goes up: a target below the database generation or above the engine generation fails with `StorageMigrationTargetInvalid`. For a database already in the engine's generation, `migrateStorage()` makes unstamped and stale tables fresh.

`MigrationReport` carries `fromGeneration`, `toGeneration`, `steps`, `refreshedTables` and `skippedTables`.

A restore ends with the same migration, so `restore()` of an archive of any version leaves the database in the engine's generation.

### Upgrade order

1. **Check the data before the deploy.** Run `validate()` and resolve `broken_record` findings: the migration skips a table with lines that are not records.
2. **Deploy the code.** While the deploy rolls out, old and new processes may run side by side — both versions take the same locks. Until the database is migrated, every provider initialization logs a warning that the database is in an older generation (PSR-3 `warning`, `E_USER_DEPRECATED` without a logger); under PHP-FPM that is one warning per request.
3. **Migrate once** from the deploy script, at a quiet moment — the code above.
4. **Restart long-lived processes** (CLI workers, queue daemons): the manifest is read once per provider instance, and until restarted such a process behaves as an engine of the previous generation. For compatible generations that is safe; a future step to an incompatible generation will require stopping every process that works with the database before the migration.

### Rolling back and mixed deploys

Rolling back from 1.1 to 1.0 takes no manual steps. The 1.0 engine does not see `.jdp/`, ignores the `dataIno` field in meta and keeps it through its own commits, and restores 1.1 archives as any other (see [backup and restore](15-backup-restore.md)). After going back to 1.1, the tables 1.0 rewrote meanwhile are stale and get repaired by their next write, `repair()` or a migration.

While 1.0 processes are still running, the tables they rewrite are served by full scans until the next write by 1.1.

### 1.0 → 1.1: generation 1 → 2

A 1.0 database works on 1.1 as it did on 1.0; the generation-2 protection — the table stamp — switches on after the migration. Step `1->2` never takes a 1.0 index on faith:

- an unstamped table gets its indexes rebuilt from the data and a stamp — the data file is not rewritten;
- a table with drifted counters is repaired with the canonical rewrite;
- a table whose stamp still matches is left as it is.

As its last act the step writes the generation-2 manifest `.jdp/format.json`.

### 1.1 → 1.2

The generation does not change. Version 1.2 keeps derived files for index lookups in `.jdp/` (see [storage format](23-storage-format.md#derived-files)) and marks that with the `compat` flags `lineOffsets` and `sortedIndexHeads`. A 1.1 database works on 1.2 right away: a table gets its files with its first write and is searched the previous way until then. `storageStatus()` lists such tables in `pendingTables` and the missing flags in `pendingFeatures`; `migrateStorage()` builds the files of every table — line offsets from the data file, a sorted index file where no head is recorded — and adds the flags to the manifest. Data and indexes stay as they are.

Database and table locks in 1.2 have a turnstile — empty files `gate.<lock file>` in `.locks/` (see [concurrency](18-concurrency.md#limitations)). Mutual exclusion is held by the lock files themselves, so 1.2 runs side by side with 1.1 and 1.0; readers of the older versions do not queue behind a waiting 1.2 writer. After a rollback the turnstile files stay in `.locks/` and get in nothing's way.

Rolling back to 1.1 or 1.0 takes no manual steps: these versions do not see the derived files and skip the `compat` flags. After going back to 1.2, the tables the older engine rewrote are searched the previous way until their next write; `migrateStorage()` builds their files at once.

The exception is relations that `FkBackingPolicyEnum::LeadingColumn` backed with a composite index (see [Indexes](06-indexes.md#service-fk-backing-indexes)). 1.1 and 1.0 count such a relation as uncovered: a `restrict` delete fails with `FkBackingIndexMissing`, `cascade` and `setNull` read the child table whole. After rolling back, run `repair()`: it builds the service index and re-points the relation to it.
