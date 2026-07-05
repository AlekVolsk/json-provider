# Concurrency model

Within a single PHP process: every file write goes through `flock(LOCK_EX)` on the target file. There are no cross-table transactions and no global lock — concurrent writes to different tables proceed in parallel.

Across processes: the same `flock` semantics apply. The provider is safe for low-concurrency multi-process use (e.g. classic mod_php / PHP-FPM workers serving different requests). It is **not** designed for high contention or for write-heavy parallel workloads.

The cache layer follows an "invalidate on write" model: any DML through the provider clears the affected table's cache entry. If you mutate the storage outside the provider, call `$db->invalidateCache($tableName)` to keep the cache honest.
