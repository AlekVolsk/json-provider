# Concurrency model

The provider uses a three-level hierarchy of inter-process locks (flock on persistent empty files in the service directory `db/.locks/`):

1. **Database** — `db.lock`. SH is taken by any DML write (insert/update/delete); EX by DDL (createTable, dropTable, migrateColumns, reorderColumns), restore, backup and whole-database repair.
2. **Tables** — `table.<name>.lock`. EX for tables being written, SH for tables only read inside the critical section (FK parents, restrict children). Acquired strictly in ascending name order.
3. **Service files** — `svc.<name>.lock`, short "leaf" sidecars for `meta.json` and `information_schema.json`. Taken last and non-composable: level 1–2 locks must not be acquired while holding a leaf.

The acquisition order is total: database → tables by name → leaves. Mode upgrades (SH→EX) and out-of-order acquisition are forbidden and raise `LOCK_ORDER_VIOLATION`. Waiting is capped at 30 seconds, then `LOCK_TIMEOUT`. Locks die with the process: a crashed holder leaves nothing locked.

The set of tables to lock for a write is derived from the schema's relations graph: the mutated table EX, transitively all CASCADE/SET_NULL children EX, RESTRICT children SH, parents of enforced relations SH. Graph cycles are safe.

## Reads

A two-tier model:

- **Full scan (lock-free).** A full read of the data file takes no locks. Correctness comes from atomic file replacement on rewrite (tmp + fsync + rename): a reader always sees either the complete old file or the complete new one. The snapshot corresponds to the moment the file was opened — inserts finishing later may not be included.
- **Index-driven selects (coordinated).** An indexed select holds the table SH lock across the index and data-row reads, so a writer can never swap the files between the index lookup and the row reads — the index+data pair is always coherent. The SH section covers only the I/O and is released before decoding.

`count()` and full-scan `select()` are lock-free.

## Writes

Every write is a read-modify-write strictly from disk: the cache is never the base of a rewrite. A full file rewrite is buffered first (an unencodable record refuses the whole operation, the file is untouched), then published via tmp + fsync + rename. Appends fsync and roll back a short write by truncation — a torn line is never acknowledged.

The first step of every write is an O(1) consistency gate: `meta.byteSize` versus the actual file size. A mismatch (crash, foreign write) is healed by the operation itself — a canonical rewrite of the table with index rebuild and a commit of the true counters. The same is the standard recovery recipe after a crashed multi-table write: desynced tables heal on their next write (or via an explicit `repairTable()`).

## Cache

The cache layer serves reads only; keys are versioned by the table state (see [caching](16-caching.md)): the key tag combines the meta line count, the physical data file size and its inode, so any committed mutation — own, from another process, or even a direct file edit that changes the size — simply retires the stale entries with no invalidation messages; the inode (fresh on every tmp+rename rewrite) also rules out A-B-A tag collisions (a same-length delete+insert, a truncate with re-import, a same-length value swap through the provider). A lock-free reader populates the cache on a miss optimistically, without locks: a concurrent writer can only make that entry hold a NEWER snapshot than its tag claims, and the tag itself stops resolving once the writer commits — travelling back in time is impossible by construction. Mutations publish the fresh snapshot under the just-committed state's tag inside their EX section. The residual window is a foreign **in-place** file edit that keeps the size (no rename); the manual exit is `$db->invalidateCache($tableName)`.

## Limitations

- POSIX-only: flock over network filesystems (NFS) and Windows are not supported, see [requirements](20-requirements-dependencies.md).
- Lock descriptors are inherited by child processes: a long-lived child spawned from inside a critical section (proc_open, exec) keeps the lock held until it exits. Do not spawn long-lived children while holding locks.
- There is no wait queue (non-blocking flock with retries): a continuous stream of short SH readers can in theory starve an EX writer up to `LOCK_TIMEOUT` under high load. The provider targets moderate concurrency.
- Cross-file transactionality (several tables as one atom) is not provided; see the self-healing recipe above.
- Upgrading the library on a live deployment requires stopping all writing processes: a writer of the old version (taking no locks) and a writer of the new one do not mutually exclude.
