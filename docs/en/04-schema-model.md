# Schema model

Schema is described by `TableSchema`, `IndexSchema`, `IndexFieldSchema`, `UniqueConstraint`, `RelationSchema`. All schema objects are immutable value objects.

## Creating a table

```php
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Query\SortDirectionEnum;

$db->createTable(TableSchema::create(
    name: 'users',
    columns: [
        'email'     => 'string',
        'name'      => 'string',
        'isBlocked' => 'bool',
    ],
    uniqueConstraints: [
        new UniqueConstraint('uq_users_email', ['email']),
    ],
    indexes: [
        new IndexSchema(
            name: 'idx_users_email',
            fields: [new IndexFieldSchema('email', SortDirectionEnum::ASC)],
        ),
    ],
));
```

`createTable()`:

- registers the table in `information_schema.json`;
- creates the data file and every declared index file (empty);
- creates the meta entry with `lastInsertedId=0`, `lineCount=0`.

## Two ways to construct a `TableSchema`

- `TableSchema::create(...)` — **factory with normalization**. Adds the PK column and PK index for you, accepts "human" descriptions. This is what application code normally uses.
- `new TableSchema(...)` — **strict constructor**. Validates the contract but does not normalize. Used by the provider when reading existing schema files; you only need this in tests where you want to verify validation behaviour.

## Table, column and index names

A name is a whitelisted identifier: it starts with a letter, digit or underscore, continues with letters, digits, underscores or hyphens, is at most 64 characters long, and contains no dots or path separators. The rules live in `Schema\IdentifierRules` and are enforced at the schema boundary (the `TableSchema`/`IndexSchema` constructors), so they cover both the DDL API and loading `information_schema.json`. A violation raises `INVALID_TABLE_NAME` / `INVALID_COLUMN_NAME` / `INVALID_INDEX_NAME`.

Reserved: the `_fk_` prefix for index names (engine-managed FK backing indexes) → `RESERVED_INDEX_NAME`; the table name `_pendingRename` (the `renameTable` crash-marker key in `meta.json`) → `INVALID_TABLE_NAME`.

By construction a valid name can never escape the database directory when used as a path segment; the storage layers additionally carry an explicit anti-traversal guard (`.`, `..`, slashes). `dropTable` with an invalid name now throws (previously a no-op).

## Allowed column types

A column type is a string value in the `columns` map. The list is **closed**: 12 base types and their `|null` variants — exactly the 24 strings enumerated by `ColumnTypes::all()`. Any other string (`'integer'`, `'datetime|nullable'`, a typo in a hand-edited schema file) is rejected by the `TableSchema` constructor with `INVALID_COLUMN_TYPE` — in the DDL API and on schema load alike. Each base type has a `|null` variant (which additionally permits `null`):

- **Primitives** — `string`, `int`, `float`, `bool`.
- **Temporal** — `date`, `time`, `timez`, `datetime`, `datetimez`. An absolute moment (`datetime`/`datetimez`) is stored in UTC and presented in the current PHP timezone; wall-clock with no moment (`date`/`time`/`timez`) is stored verbatim; see [Temporal types and timezones](#temporal-types-and-timezones).
- **Numeric parts** — `year`, `month`, `day`. Plain integers with a range check, never timezone-shifted; `year` may be negative (BC).

To avoid scattering these literals across the code, use the `Schema\ColumnTypes` constants (a constants-only class):

```php
use AV\JsonProvider\Schema\ColumnTypes;

$db->createTable(TableSchema::create(
    name: 'users',
    columns: [
        'email'     => ColumnTypes::STRING,
        'age'       => ColumnTypes::INT_NULLABLE,
        'isBlocked' => ColumnTypes::BOOL,
        'createdAt' => ColumnTypes::DATETIME,
        'birthDate' => ColumnTypes::DATE_NULLABLE,
    ],
));
```

| Constant | Value |
| - | - |
| `ColumnTypes::STRING` | `string` |
| `ColumnTypes::INT` | `int` |
| `ColumnTypes::FLOAT` | `float` |
| `ColumnTypes::BOOL` | `bool` |
| `ColumnTypes::STRING_NULLABLE` | `string\|null` |
| `ColumnTypes::INT_NULLABLE` | `int\|null` |
| `ColumnTypes::FLOAT_NULLABLE` | `float\|null` |
| `ColumnTypes::BOOL_NULLABLE` | `bool\|null` |
| `ColumnTypes::DATE` | `date` |
| `ColumnTypes::TIME` | `time` |
| `ColumnTypes::TIMEZ` | `timez` |
| `ColumnTypes::DATETIME` | `datetime` |
| `ColumnTypes::DATETIMEZ` | `datetimez` |
| `ColumnTypes::YEAR` | `year` |
| `ColumnTypes::MONTH` | `month` |
| `ColumnTypes::DAY` | `day` |

Every type has a `*_NULLABLE` constant (e.g. `ColumnTypes::DATETIME_NULLABLE` → `datetime|null`). The PK type (`PrimaryKey::TYPE`) reuses `ColumnTypes::INT`, so the `int` literal lives in exactly one place.

Records hold scalars and `null`; nested arrays are not supported.

## Value validation

Types are enforced on every write (`insert`, `update`, and `where` values), not just documented. The contract is **strict**:

- a value must match the declared PHP type exactly; the only widening allowed is `int` into a `float` column (`10 → 10.0`);
- `NAN`, `INF` and `-INF` are never written into a `float` column — `NON_FINITE_FLOAT` (JSON cannot represent them); the guard fires on `insert`/`update`, before the disk is touched;
- a written string must be valid UTF-8, otherwise `INVALID_UTF8` — likewise on `insert`/`update`, before the disk is touched;
- `null` is accepted only on a `<type>|null` column;
- on `insert`, a missing non-nullable column is an error (a missing nullable column becomes `null`); `update` is a patch — it validates only the keys it is given and leaves the rest untouched;
- keys not present in the schema are dropped;
- unknown/custom type strings cannot exist: the schema boundary rejects them (`INVALID_COLUMN_TYPE`), so every column is always validated against one of the 24 known types.

Violations raise `StorageException` with a specific key — `TYPE_MISMATCH`, `NULL_NOT_ALLOWED`, `REQUIRED_COLUMN_MISSING`, `NON_FINITE_FLOAT`, `INVALID_UTF8`, `INVALID_TEMPORAL_VALUE`, `ZERO_DATE`, `NUMERIC_PART_OUT_OF_RANGE` — see [Exceptions & localization](17-exceptions-localization.md).

### `where` value typing policy

Condition values are checked by the same contract as writes — the first violation throws and the query never executes:

| Operator | Value rule |
| - | - |
| `=` | scalar or `null` (`null` is allowed regardless of nullability and simply matches `null` cells) |
| `>` `>=` `<` `<=` | non-`null` scalar of the column's exact type |
| `BETWEEN` | array of exactly two non-`null` scalars following the range rule; otherwise `CONDITION_MALFORMED` |
| `IN` | array whose elements follow the `=` rule; an empty array is valid and matches nothing; a non-array → `CONDITION_MALFORMED` |
| `LIKE` | a string pattern; string and `date`/`time`/`timez` columns (matched against the stored=local form); `datetime`/`datetimez` → `LIKE_ON_INSTANT_UNSUPPORTED` |

The single coercion is an `int` condition on a `float` column (`99 → 99.0`); numeric **strings** (`'5'` for `int`, `'9.5'` for `float`) are rejected, as are cross-type values (`1` for `bool` etc.) — `CONDITION_TYPE_MISMATCH`. `NAN`/`INF` against a `float` column → `NON_FINITE_FLOAT` (the same guard as on write). `datetime`/`datetimez` conditions are encoded to the stored UTC form; `date`/`time`/`timez` conditions match the stored (verbatim=local) form. A column missing from the schema in `where`/`orderBy`/`isDistinct`/`selectColumn` → `QUERY_UNKNOWN_COLUMN`.

## Float format on disk

JSON has a single number type, so a float without a fractional part could collapse into an int on re-read. The provider closes this from both sides:

- **write** — a float is always stored with its fraction (`99.0` → `"price":99.0`, the `JSON_PRESERVE_ZERO_FRACTION` flag), so re-reading yields a PHP `float`;
- **read** — int values in `float` columns are widened to `float` on every read path (full scan, indexed selects, `count`, cache fill, DTO hydration). Legacy rows written by older versions (`"price":99`) and external file edits are therefore indistinguishable from fresh writes: strict `=`/`IN` comparisons against `99.0` find them.

No migration of existing databases is required; to rewrite files into the new format, run `optimizeTable()` once per table. Zero-sign nuance: a freshly written `-0.0` keeps its sign, while a legacy `-0` reads back as `int 0` and widens to an unsigned `0.0` — the sign of old rows is not restored.

## Unique constraint semantics

Constraints from `uniqueConstraints` are checked on `insert` and `update` before anything is written. The rules follow SQL:

- a record with `null` (or a missing field) in **any** constraint field does not participate in the check — any number of such records may coexist, including under composite constraints;
- a conflict exists only between two records whose constraint fields are **all** non-`null` and pairwise equal;
- the comparison is **type-strict**: `1`, `1.0`, `'1'` and `true` are four distinct values that never conflict with each other (no SQL-style numeric merging). In a declared `float` column an `int` is widened to `float` already on write, so `1` and `1.0` are one key there; `-0.0` and `0.0` are one key too (the key follows the query engine's strict equality).

`UniqueConstraint::keyPart()` canonicalizes a single key value; `UniqueConstraint::keyOf()` returns `null` for records that do not participate.

## Temporal types and timezones

Temporal columns exist so that a moment written by a process in one timezone reads back as the same moment for a process in another. An absolute moment (`datetime`/`datetimez`) is always stored in UTC on disk and converted to the current PHP timezone (`date_default_timezone_get()`) on the way out. Wall-clock values with no moment (`date`, `time`, `timez`) are stored **verbatim** — as-is, never timezone-shifted. All arithmetic goes through `DateTimeImmutable`.

| Type | Stored | Timezone-shifted | Notes |
| - | - | - | - |
| `date` | `Y-m-d` (verbatim) | no | a calendar date has no instant |
| `time` | `H:i:s` (verbatim) | no | time of day, second resolution |
| `timez` | `H:i:s.v` (verbatim) | no | time of day with milliseconds |
| `datetime` | `Y-m-d H:i:s` (UTC) | yes | full instant, second resolution |
| `datetimez` | `Y-m-d H:i:s.v` (UTC) | yes | full instant with milliseconds |
| `year` / `month` / `day` | integer | no | range-validated parts |

**Sub-second precision.** The second-resolution kinds (`time`, `datetime`) reject any fraction; the millisecond kinds (`timez`, `datetimez`) accept 1–3 digits and reject more — never silently truncating (`TEMPORAL_FRACTION_UNSUPPORTED`). For `datetime`/`datetimez` a TZ offset beyond ±14:00 (`+25:00`, `+00:99`) raises `INVALID_TEMPORAL_VALUE`.

Input is accepted **only** in the correct system format — no dots, slashes, or reversed order, and no zero dates (`0000-00-00` throws):

```php
date_default_timezone_set('Europe/Moscow'); // UTC+3

$db->table('events')->insertByArray([
    'happensAt' => '2026-07-05 12:30:00',    // stored as 2026-07-05 09:30:00 (UTC)
    'onDate'    => '2026-07-05',              // stored verbatim
    'atTime'    => '23:30:00',               // stored verbatim (wall-clock)
]);

// read back in the same zone — exact round-trip
$row = $db->table('events')->where('id', '=', 1)->selectOneByArray();
$row['happensAt']; // '2026-07-05 12:30:00'
```

`datetime` / `datetimez` also accept the ISO forms — the `T` separator, an explicit `Z`, and a numeric offset (`±HH:MM`), all converted correctly to UTC:

```text
2026-07-05T12:30:00        // T separator, interpreted in the local zone
2026-07-05T12:30:00Z       // explicit UTC
2026-07-05T12:30:00+05:00  // explicit offset -> converted to UTC
2026-07-05T12:30:00.250Z   // with milliseconds (datetimez)
```

Filter values are encoded the same way, so a query stated in **local** time still matches the stored UTC form — including through an index:

```php
// finds the row inserted above, comparing against the stored UTC value
$db->table('events')->where('happensAt', '=', '2026-07-05 12:30:00')->selectOneByArray();
$db->table('events')
    ->where('happensAt', 'BETWEEN', ['2026-07-01 00:00:00', '2026-07-31 23:59:59'])
    ->orderBy('happensAt', 'asc')
    ->selectAllByArray();
```

> `date`, `time` and `timez` are intentionally **not** timezone-shifted: a bare date and a bare wall-clock time have no absolute instant, and anchoring them at local midnight/date to move into UTC is not round-trip stable — a date would read back as a different day, and a time would drift under DST. When you need a timezone-anchored point in time, use `datetime`/`datetimez`.

## Relations

```php
// inside information_schema.json
{
    "relations": [
        {
            "from": "boards",
            "foreignKey": "ownerId",
            "to": "users",
            "references": "id",
            "type": "belongsTo",
            "onDelete": "cascade",
            "backingIndex": "_fk_ownerId"
        }
    ]
}
```

`type` is one of `belongsTo`, `hasMany`, `hasOne`. `onDelete` and `onUpdate` support `noAction`, `cascade`, `setNull`, `restrict`. The provider enforces the action when a parent record is deleted or its referenced field is updated. `backingIndex` is engine-managed: the name of the child-table index serving FK probes (see [indexes](06-indexes.md)); it is set by the relation API, never by hand.

### Canonical edge direction

Which side is the **child** (physically holds the FK column) depends on the relation type:

- `belongsTo`: the child is the **from** (declaring) table, the parent is to. `boards.ownerId -> users.id` is declared from `boards`.
- `hasMany` / `hasOne`: the child is the **to** table, the parent is from. The same edge in the mirrored notation: `from: users, to: boards, foreignKey: ownerId`.

`foreignKey` is always a column of the **child**, `references` a column of the **parent**. Both notations describe the same canonical edge; the engine (cascades, restrict, lock plans) works only with the canonical resolution, so both notations enforce identically.

> **Upgrade warning.** Previously the engine treated the from table as the child for ANY relation type. Legacy `hasMany`/`hasOne` declarations face two outcomes: (a) those declared "engine-style" (from = child) now fail with a loud `RELATION_COLUMN_NOT_FOUND` — the FK column does not exist in the canonical child table; (b) those declared "intuitively" (the FK column really lives in the to table) were a no-op for years and now **SILENTLY ACTIVATE** — including cascade deletion of children. Audit every `hasMany`/`hasOne` in your schemas before upgrading.

### What the actions mean

- `onDelete` fires when a parent row is deleted: `cascade` deletes the referencing children (transitively), `setNull` nulls their FK, `restrict` refuses the delete while at least one reference exists (MySQL-immediate semantics: a reference counts even when the referencing child is deleted by the same statement — a self-referential restrict table cannot be emptied by one delete-all; delete leaves before roots).
- `onUpdate` fires when the referenced column value of the parent changes. It cannot be declared on the primary key `id` (`RELATION_ON_UPDATE_ON_PK`): `id` is immutable, so the action could never fire. It works only for relations referencing a non-PK unique column — there `cascade` really rewrites the children's FK values.

### Declaring relations — API only

Relations are declared and removed via `addRelation()` / `dropRelation()` (see [DDL operations](12-schema-mutations.md)); hand-editing `information_schema.json` is unsupported. `addRelation` validates the declaration:

1. both tables exist → otherwise `TABLE_NOT_FOUND`;
2. the FK column exists in the child and the referenced column in the parent → `RELATION_COLUMN_NOT_FOUND` (with a hint on which table holds the FK for the declared type);
3. the base column types match, the `|null` suffix is ignored (`int|null` → `int` is legal) → `RELATION_TYPE_MISMATCH`;
4. the referenced column is `id` or is covered by a single-column unique constraint → `RELATION_REFERENCES_NOT_UNIQUE`;
5. `onUpdate` is not declared on the PK → `RELATION_ON_UPDATE_ON_PK`;
6. `setNull` requires a nullable FK column → `FOREIGN_KEY_SET_NULL_NOT_NULLABLE`;
7. no edge with the same canonical quadruple (child.column → parent.column) is declared yet — in either notation → `RELATION_ALREADY_EXISTS`.

Legacy edges loaded from the schema are not re-validated on load (loading is strict structurally only). The FK engine re-checks their semantics in the plan phase — but **only for executable edges** (action ≠ `noAction` for the current event): a dead edge with mismatched types does not block working deletes/updates, while an executable one fails with the same `RELATION_COLUMN_NOT_FOUND`/`RELATION_TYPE_MISMATCH` before anything is written.

### Strict schema loading

`information_schema.json` is parsed strictly: a structurally broken entry is not silently skipped — it fails the load with the exact address of the problem. A silently dropped relation would mean a cascade or restrict the author believed was enforced simply stopped executing.

- a relation entry that is not an object, or lacks string `from`/`foreignKey`/`to`/`references` keys (catches typos like `form`), or has an unknown `type`, or carries a non-string `backingIndex` → `RELATION_ENTRY_INVALID`; table/column names inside relations pass the same identifier whitelist the tables themselves do;
- a **present** `onDelete`/`onUpdate` outside `noAction`/`cascade`/`setNull`/`restrict` (e.g. `CASCADE` or `set_null`) → `RELATION_ACTION_INVALID`; an absent key is the legal `noAction` default;
- a broken index entry (missing name, empty `fields`, a direction outside case-sensitive `asc`/`desc`), unique constraint (missing name or fields) or table definition (non-object definition, non-string column type, missing `tables` key) → `INVALID_SCHEMA` with details; an empty `tables` collection stays valid.

Relation semantics (table/column existence, type compatibility) are not validated on load — that belongs to the relation API and the FK engine at execution time.

## Comments — table and column descriptions

The schema can carry human-readable documentation **beside** the structure: what a table is for, and what each column means. Comments are pure metadata — they never affect the PK contract, index resolution, or record shape.

On disk they live inside the table definition as two optional keys: `tableComment` (a string) and `columnComment` (a `column name => description` map, ordered to follow the columns):

```json
{
    "tables": {
        "respondents": {
            "tableComment": "Form respondents — used for dedup and submission accounting",
            "columns": { "id": "int", "formId": "int", "dedupHash": "string" },
            "columnComment": {
                "dedupHash": "Hash guarding against a repeat submission by one person"
            },
            "unique": [],
            "indexes": []
        }
    }
}
```

Declare them at table creation via the `TableSchema::create()` factory. Comments for columns that do not exist (and empty strings) are dropped silently:

```php
$db->createTable(TableSchema::create(
    name: 'respondents',
    columns: ['formId' => 'int', 'dedupHash' => 'string'],
    tableComment: 'Form respondents — used for dedup and submission accounting',
    columnComment: [
        'dedupHash' => 'Hash guarding against a repeat submission by one person',
    ],
));
```

Comments survive every schema mutation: they are re-persisted on `createTable` of any table and carried across `reorderColumns`. They are also included verbatim in `backup()` and preserved by `restore()`.

Manipulate them independently of the structure — these are schema-only meta-operations that rewrite `information_schema.json` but never touch table data, indexes, meta, or the cache:

```php
$db->setTableComment('respondents', 'Form respondents');
$db->setTableComment('respondents', null);                       // clear

$db->setColumnComment('respondents', 'formId', 'Parent form id');
$db->setColumnComment('respondents', 'formId', null);            // clear one column

$db->setColumnComments('respondents', [                          // replace the whole map
    'formId'    => 'Parent form id',
    'dedupHash' => 'Anti-duplicate hash',
]);
$db->setColumnComments('respondents', ['formId' => '...'], merge: true);   // merge on top
```

`setColumnComment` / `setColumnComments` throw `StorageException` with key `COLUMN_NOT_FOUND` if a name is not a declared column.

Read them back:

```php
$db->getTableComment('respondents');            // string|null
$db->getColumnComment('respondents', 'formId'); // string|null
$db->getColumnComments('respondents');          // array<string,string>

$db->describeTable('respondents');
// [
//   'name' => 'respondents',
//   'comment' => 'Form respondents',
//   'columns' => [
//       ['name' => 'id',        'type' => 'int',    'comment' => null],
//       ['name' => 'formId',    'type' => 'int',    'comment' => 'Parent form id'],
//       ...
//   ],
// ]
```
