# When to use it (and when not to)

**Use it for** compact, low-traffic data stores where a real RDBMS would be overkill:

- internal tools and admin panels with modest record counts;
- configuration stores with relational structure;
- single-process or low-concurrency PHP services;
- prototypes that may later migrate to a real database.

**Do not use it for** high-write-throughput workloads, large datasets that will not fit cache comfortably, or scenarios that require complex joins, transactions across tables, or strict isolation. Reach for PostgreSQL, SQLite, MySQL, etc. instead.

The provider's contracts are intentionally narrow so that migrating to a real database later is mechanical — primary keys, basic foreign keys, indexes and a familiar query builder all map cleanly to SQL.
