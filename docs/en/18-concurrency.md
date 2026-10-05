# Concurrency model

The provider uses a three-level hierarchy of inter-process locks (flock on persistent empty files in the service directory `db/.locks/`):

1. **Database** — `db.lock`. SH is taken by any DML write (insert/update/delete); EX by DDL (createTable, dropTable, migrateColumns, reorderColumns), restore, backup and whole-database repair.
2. **Tables** — `table.<name>.lock`. EX for tables being written, SH for tables only read inside the critical section (restrict children). Acquired strictly in ascending name order.
3. **Service files** — `svc.<name>.lock`, short "leaf" sidecars for `meta.json` and `information_schema.json`. Taken last and non-composable: level 1–2 locks must not be acquired while holding a leaf.

The acquisition order is total: database → tables by name → leaves. Mode upgrades (SH→EX) and out-of-order acquisition are forbidden and raise `JsonProviderLockException`. Waiting is capped at 30 seconds, then `JsonProviderLockException`. Locks die with the process: a crashed holder leaves nothing locked.

The set of tables to lock for a write is derived from the schema's relations graph. An `insert` takes EX on its own table only. A `delete` takes EX on its table and follows the `onDelete` actions: `cascade` and `setNull` children EX, `restrict` children SH; children deleted by a cascade go on along their own `onDelete` actions, nulled ones along their `onUpdate` actions, since nulling is a value change. An `update` follows the `onUpdate` actions only: relations on `id` have none, so an `update` of such a table locks only the table itself, not the tree of descendants. Parents are never locked: no FK action reads a parent row, and a delete or key update of the parent locks the child table, so it serializes with an insert there. Graph cycles are safe. An engine access to a table outside the held set is a `JsonProviderLockException`, case `LockOrderTableOutsideHeldSet`, not a race.

## Reads

A two-tier model:

- **Full scan (lock-free).** A full read of the data file takes no locks. Correctness comes from atomic file replacement on rewrite (tmp + fsync + rename): a reader always sees either the complete old file or the complete new one. The snapshot corresponds to the moment the file was opened — inserts finishing later may not be included.
- **Index-driven selects (a snapshot under the lock).** Under the table SH lock an indexed select checks that the index can be trusted and opens the files: the data file and the sorted head of the index file. The index tail is read into memory; the whole index file is read into memory when its head is not recorded or the select runs in index order without conditions. Then the lock is released, and the search over the head and the row reads go through the open files, within the data file size as of the snapshot. A writer rewrites the table into new files (tmp + fsync + rename) or appends rows at the end; no version changes the bytes of the open files within the snapshot, so the index+data pair is coherent and the answer comes from the version as of the snapshot. Line offsets are used only when the derived file describes exactly the open data file; otherwise the rows are read in one pass over it.

Full-scan `select()` and a `count()` answered by a full scan or from the meta counter are lock-free; a `count()` answered through an index takes a snapshot under the table SH lock, as an index select does.

## Writes

Every write is a read-modify-write strictly from disk: the cache is never the base of a rewrite. A full file rewrite is buffered first (an unencodable record refuses the whole operation, the file is untouched), then published via tmp + fsync + rename. Appends fsync and roll back a short write by truncation — a torn line is never acknowledged.

The first step of every write is an O(1) consistency gate: `meta.byteSize` versus the actual file size, and in a generation-2 database also the file's inode versus the `dataIno` stamp in meta. The stamp catches a rewrite interrupted between the file rename and the counter commit even when the size matched (see [storage format](23-storage-format.md)). A mismatch (crash, foreign write) is healed by the operation itself — a canonical rewrite of the table with index rebuild and a commit of the true counters. The same is the standard recovery recipe after a crashed multi-table write: desynced tables heal on their next write (or via an explicit `repairTable()`); consistency between tables after such a crash is checked by `validate()` — see [mutations](10-mutations.md#how-fk-actions-execute).

## Cache

The cache layer serves reads only; keys are versioned by the table state (see [caching](16-caching.md)): the key tag combines the meta line count, the physical data file size and its inode, so any committed mutation — own, from another process, or even a direct file edit that changes the size — simply retires the stale entries with no invalidation messages; the inode (fresh on every tmp+rename rewrite) also rules out A-B-A tag collisions (a same-length delete+insert, a truncate with re-import, a same-length value swap through the provider). A lock-free reader populates the cache on a miss optimistically, without locks: a concurrent writer can only make that entry hold a NEWER snapshot than its tag claims, and the tag itself stops resolving once the writer commits — travelling back in time is impossible by construction. Mutations publish the fresh snapshot under the just-committed state's tag inside their EX section. The residual window is a foreign **in-place** file edit that keeps the size (no rename); the manual exit is `$db->invalidateCache($tableName)`.

## Limitations

- POSIX-only: flock over network filesystems (NFS) and Windows are not supported, see [requirements](20-requirements-dependencies.md).
- Lock descriptors are inherited by child processes: a long-lived child spawned from inside a critical section (proc_open, exec) keeps the lock held until it exits. Do not spawn long-lived children while holding locks.
- A process waits for a lock with non-blocking flock retried every 2 ms; there is no wait queue at the OS level. So that a stream of overlapping SH readers cannot starve an EX writer, database and table locks have a turnstile — a file `gate.<lock file>` in `.locks/`. A writer holds it while it waits for its lock, and a reader passes through it before taking its own, so readers arriving after a writer wait behind it. Leaf locks have no turnstile: they are held briefly.
- Cross-file transactionality (several tables as one atom) is not provided; see the self-healing recipe above.
- Versions 1.0, 1.1 and 1.2 take the same lock files in the same order, so during a deploy old and new processes may run side by side (see [rolling back and mixed deploys](07-migrations.md#rolling-back-and-mixed-deploys)). Only 1.2 takes the turnstile: readers of 1.1 and 1.0 do not queue behind a waiting writer and hold the SH lock for the whole index read. A writer of a build that takes no locks does not exclude them — stop such processes before upgrading.
- The storage format manifest is read once per provider instance: a long-lived process sees a format migration run by another process only after a restart.
