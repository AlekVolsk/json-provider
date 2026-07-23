# Primary key contract

Every table has a primary key. It is **not** a configuration knob — it is an invariant of the provider:

- the column is named `id`,
- the type is `int`,
- it is always the first column in `columns`,
- it is auto-incrementing (allocated by the provider),
- ids are never reused after delete (gaps are normal); an id allocated by an insert that crashed before committing the record is burned too — a normal gap. The single exception is recovery of a lost meta entry (self-healing, `repair`, `restore`): the counter is restored as `max(id)` of the surviving records, so ids of deleted "top" rows may be handed out again,
- the table always has a PK index named `pk`, located at index position 0.

Constants live in `Schema\PrimaryKey`:

```php
PrimaryKey::FIELD;  // 'id'
PrimaryKey::TYPE;   // 'int'
```

Violations of this contract — when reading a tampered schema, when constructing `new TableSchema(...)` directly, or when registering a malformed schema — raise `StorageException` with the `PK_CONTRACT_VIOLATED` key.

The factory `TableSchema::create(...)` papers over user-side mistakes: prepends a missing `id`, moves a misplaced `id`, prepends a missing PK index. It does not paper over a wrong **type** for `id` — that is a semantic error and always throws.
