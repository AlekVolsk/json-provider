# DTO mapping — typed objects

The query builder has two record surfaces on the same `table()` handle,
selected by method name:

- **object methods** — `insert`, `update`, `selectAll`, `selectOne` — speak
  typed DTOs;
- the **`*ByArray` twins** — `insertByArray`, `updateByArray`,
  `updateByIdByArray`, `selectAllByArray`, `selectOneByArray` — speak the raw
  `array<string,null|scalar>` records and are always available.

The array surface is the foundation (everything else in these docs uses it);
DTO mapping is an adapter on top. Nothing about the storage, schema, or
validation changes — a DTO is flattened to a record on write and rebuilt from
one on read.

## Defining a DTO

A DTO is a plain, immutable, `final` class. The only library touch-point is the
`#[JsonProviderRecord]` attribute naming its table — it is pure metadata and
does not affect finality or immutability. Column **types are not redeclared**:
the schema owns them, the property's PHP type only says how you want the value
in code.

```php
use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

#[JsonProviderRecord('users')]
final class User
{
    public function __construct(
        public int $id,
        public string $email,
        public ?int $age,
        public Status $status,               // backed enum
        public \DateTimeImmutable $createdAt, // datetime column
    ) {}
}
```

Only the constructor's promoted parameters are mapped. Columns the DTO omits
keep the array-core rules (a missing nullable column is `null`, a missing
non-nullable one is an error on insert). Reading is reflection-free — the map
is compiled once at registration.

## Registration

Bind the class after its table exists. The table comes from the attribute, so
there is no hand-written `table => class` map:

```php
$db->registerDto(User::class, Order::class);
```

Registration **compiles and validates** the DTO against the schema
immediately — property↔column name, PHP type ↔ column type, nullability, and
enum backing must all line up. Any mismatch throws `StorageException`
(`DTO_SCHEMA_MISMATCH`) here, not on the first query.

## Type mapping

| Column type | DTO property type |
| - | - |
| `string` / `int` / `float` / `bool` | the same scalar (`int` widens into a `float` property) |
| `date` / `time` / `timez` / `datetime` / `datetimez` | `\DateTimeImmutable` |
| `year` / `month` / `day` | `int` |
| `int` or `string` under an enum | a `BackedEnum` whose backing matches |
| `<type>\|null` | a nullable property `?T` |

Temporal columns become real `DateTimeImmutable` objects; the timezone
conversion (UTC on disk, local in code) is the same as the array API — see
[Schema model → Temporal types](04-schema-model.md#temporal-types-and-timezones).

## Names — snake_case ↔ camelCase

By default a property maps to the snake_case form of its name (`createdAt` →
`created_at`), so no attribute is needed for the common case. Only a genuinely
irregular column name needs an override:

```php
use AV\JsonProvider\Mapping\Attribute\JsonProviderColumn;

public function __construct(
    #[JsonProviderColumn('legacy_title')]
    public string $title,
) {}
```

## Enums

A backed enum property is stored by its backing value and rebuilt on read:

- **write** — `$status->value` is stored (a plain `string`/`int` column);
- **read** — `Status::from(<stored>)` rebuilds the case.

A stored value with no matching case throws `INVALID_ENUM_VALUE`. Because the
DTO's nullability must match the column's, a nullable column requires a nullable
enum property (`?Status`), so a stored `null` maps cleanly to `null`.

> If you change a DTO's contract (rename/remove an enum case, tighten
> nullability), migrate the existing values in the database. Otherwise reads of
> old rows will fail with `INVALID_ENUM_VALUE` / `DTO_HYDRATION_FAILED`.

## The object methods

```php
// insert — returns the new id (the DTO is not mutated)
$id = $db->table('users')->insert(new User(
    id: 0,                       // ignored; assigned by the provider
    email: 'a@b.c',
    age: 30,
    status: Status::Active,
    createdAt: new \DateTimeImmutable(),
));

// selectOne — a DTO or null
$user = $db->table('users')->where('id', '=', $id)->selectOne();

// selectAll — a single-pass generator of DTOs (lazy hydration)
foreach ($db->table('users')->where('age', '>', 18)->selectAll() as $user) {
    // ...
}
// need a re-iterable list? iterator_to_array(...)

// update — overwrites the row identified by the DTO's own id with all its
// fields (accumulated where() is ignored)
$db->table('users')->update($user);
```

`insert` returns an `int` id (consistent with `insertByArray`), not the object —
the object is already in hand. A partial patch stays on the array surface
(`updateByArray(['age' => 31])`); `update($dto)` is a whole-row overwrite, which
costs nothing extra because a record is always rewritten in full.

Calling an object method on a table with no DTO registered throws
`DTO_NOT_REGISTERED` — use the `*ByArray` twin instead.
