# Storage format and version compatibility

A database on disk has a storage format generation. Generation 1 is everything the 1.0 engines write. Generation 2 arrived with 1.1. Compatibility runs both ways: the 1.0 engine works with a generation-2 database, the 1.1 engine with a generation-1 one, and rolling the library back to 1.0 takes no manual steps.

## Format manifest

The generation and the feature flags live in `.jdp/format.json`:

```json
{
    "generation": 2,
    "compat": [],
    "roCompat": [],
    "incompat": []
}
```

A generation-1 database has no manifest — its absence is what marks generation 1. `createDatabase()` creates a database in generation 2 right away.

`.jdp/` is a dot-directory, like `.locks/`: the validator and `repair()` neither see nor touch it, and it never enters a backup archive. So the 1.0 engine, which knows nothing of the manifest, does not report it as an orphan and does not delete it.

The three flag lists tell an engine what to do with a format feature it does not know:

| List | An engine that does not know the flag |
| - | - |
| `compat` | ignores it |
| `roCompat` | opens the database for reading only: every write fails with `StorageReadOnly` |
| `incompat` | refuses to open the database: `StorageFeatureUnsupported` |

A generation newer than the engine knows is refused as well: `StorageGenerationUnsupported`. A manifest that cannot be trusted (not JSON, not an object, a generation below 2, a flag list that is not made of non-empty strings) is refused with `StorageManifestCorrupt`: a guessed generation could hide a flag the engine must not ignore. Unknown top-level keys are skipped, and a missing flag list counts as an empty one.

The 1.0 engine does not read the manifest at all, so everything generation 2 adds is compatible with it by construction, and generation 2 sets no flags. The flags are there for the generations to come: versions from 1.1 on will refuse a format they do not know instead of misreading it.

## Table stamp

A full table rewrite replaces the data file through a rename, then rebuilds the indexes, then commits the counters to meta. If the process dies after the rename and the new file happens to have the old size (a value changed to one of the same length, say a foreign key `2 → 4`), a size-only check takes the old indexes for valid. A query by index then misses the changed row, and a `restrict` probe lets you delete a parent that only this row references.

Generation 2 closes that window with a stamp: every full rewrite records the inode of the new data file in meta, next to the counters — the `dataIno` field. A rename always changes the inode, even when the size stays the same.

One trust check serves index queries, `restrict` probes, the check before a write, `count()` without conditions and the validator:

| Table state | When | Indexes trusted | What happens |
| - | - | - | - |
| fresh | size and inode match the stamp | yes | — |
| unstamped | size matches, no stamp: a 1.0 engine created or restored the table | yes, by size, as in 1.0 | a warning; the stamp comes with the next full rewrite, `repair()` or migration |
| stale | size matches, inode does not: the file was replaced after the last stamped commit | no | a warning; queries fall back to full scans, the next write repairs the table with the canonical rewrite |
| drift | the size does not match, or meta has no entry | no | as in 1.0: full scans, repair by the next write |

An append keeps the inode, so the table stays fresh — after a 1.0 `insert` too. A rewrite by the 1.0 engine leaves the table stale until the next write by the new engine. `renameTable` moves the files together with their inodes, so the stamp stays valid.

`validate()` lists unstamped and stale tables as `table_unverified` (info; `context.freshness` is `unstamped` or `stale`); `repair()` rebuilds their indexes from the data and stamps them without rewriting the data file. When the file holds lines that are not records, `repair()` does not stamp it: the indexes would skip those lines.

A generation-1 database writes and checks no stamps — trust goes by size alone, exactly as in 1.0.

## A generation-1 database on the new engine

It works as it did on 1.0: the new engine writes neither a manifest nor stamps. Every provider initialization — under PHP-FPM, every request — logs a warning that the database is in generation 1 and the generation-2 protection is off until it is migrated.

Warnings go to the provider's PSR-3 logger at the `warning` level. Without a logger they go to the PHP error log through `trigger_error()`:

| Warning | Without a logger |
| - | - |
| the database is in generation 1 | `E_USER_DEPRECATED` |
| a table is unstamped or stale (once per table per provider instance) | `E_USER_DEPRECATED` |
| the database is open read-only | `E_USER_WARNING` |

Under load a generation-1 database logs a warning on every request. That is deliberate, so that an unmigrated database shows up in the logs; [migrate it](07-migrations.md#versioned-migrations) to make the warning go away.

## Migration and rollback

Bringing the database to the engine's generation (`storageStatus()`, `migrateStorage()`), the library upgrade order, rolling back to the previous version and mixed deploys are described in [Versioned migrations](07-migrations.md#versioned-migrations).
