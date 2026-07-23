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

## Allowed column types

A column type is a string value in the `columns` map. Each base type has a `|null` variant (which additionally permits `null`):

- **Primitives** — `string`, `int`, `float`, `bool`.
- **Temporal** — `date`, `time`, `timez`, `datetime`, `datetimez`. Values that carry a moment (everything except a bare `date`) are stored in UTC and presented in the current PHP timezone; see [Temporal types and timezones](#temporal-types-and-timezones).
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
- unknown/custom type strings are passed through unchecked (backward compatibility).

Violations raise `StorageException` with a specific key — `TYPE_MISMATCH`, `NULL_NOT_ALLOWED`, `REQUIRED_COLUMN_MISSING`, `NON_FINITE_FLOAT`, `INVALID_UTF8`, `INVALID_TEMPORAL_VALUE`, `ZERO_DATE`, `NUMERIC_PART_OUT_OF_RANGE` — see [Exceptions & localization](17-exceptions-localization.md).

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

Temporal columns exist so that a moment written by a process in one timezone reads back as the same moment for a process in another: instant-bearing values are always stored in UTC on disk and converted to the current PHP timezone (`date_default_timezone_get()`) on the way out. All arithmetic goes through `DateTimeImmutable`.

| Type | Stored (UTC) | Timezone-shifted | Notes |
| - | - | - | - |
| `date` | `Y-m-d` | no | a calendar date has no instant — stored verbatim |
| `time` | `H:i:s` | yes | time of day, second resolution |
| `timez` | `H:i:s.v` | yes | time of day with milliseconds |
| `datetime` | `Y-m-d H:i:s` | yes | full instant, second resolution |
| `datetimez` | `Y-m-d H:i:s.v` | yes | full instant with milliseconds |
| `year` / `month` / `day` | integer | no | range-validated parts |

Input is accepted **only** in the correct system format — no dots, slashes, or reversed order, and no zero dates (`0000-00-00` throws):

```php
date_default_timezone_set('Europe/Moscow'); // UTC+3

$db->table('events')->insertByArray([
    'happensAt' => '2026-07-05 12:30:00',    // stored as 2026-07-05 09:30:00 (UTC)
    'onDate'    => '2026-07-05',              // stored verbatim
    'atTime'    => '23:30:00',               // stored as 20:30:00 (UTC)
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

> A bare `date` is intentionally **not** timezone-shifted: a date has no instant, and anchoring it at local midnight to move it into UTC is not round-trip stable (it would read back as a different day). When you need a timezone-anchored point in time, use `datetime`.

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
            "onDelete": "cascade"
        }
    ]
}
```

`type` is one of `belongsTo`, `hasMany`, `hasOne`. `onDelete` and `onUpdate` support `noAction`, `cascade`, `setNull`, `restrict`. The provider enforces the action when records are deleted or the referenced field is updated.

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
