# Changelog

The package version stays 1.0.0 across the audit-fix rollout; production
databases are updated manually in place. This file records behavioral and
API changes an integrator must know when updating.

## Unreleased

### Storage format

- Float columns now keep their zero fraction on disk: `99.0` is written as
  `"price":99.0` (`JSON_PRESERVE_ZERO_FRACTION` on append, full rewrite and
  the insert probe), so floats decode back as PHP floats. Legacy rows
  written as bare ints (`"price":99`) are widened to float on every read
  path; no migration is required. Optionally run `optimizeTable()` once per
  table to rewrite files in the new format. The sign of a legacy `-0` row
  is not restored (`-0` → int 0 → float 0.0); freshly written `-0.0` keeps
  its sign. Flush external caches (Redis/APCu/Memcached) on deploy: entries
  warmed by the old version hold unwidened ints.

### API

- `UniqueConstraint::keyOf()` signature changed from `string` to `?string`:
  it returns `null` when any constraint field is `null` or missing (the
  record does not participate in the constraint). New public
  `UniqueConstraint::keyPart()` canonicalizes a single unique-key value.

### Behavior

- Unique constraints follow SQL NULL semantics: any number of records with
  `null` in a constraint field coexist; previously `null` collided with
  `''` and `0` through string casting.
- Unique keys are type-strict: `1`, `1.0`, `'1'` and `true` are four
  distinct values and never conflict with each other. In a declared
  `float` column an int is widened to float on write and read, so `1` and
  `1.0` there remain one key.
- `NAN`, `INF` and `-INF` are rejected on write into float columns with
  `NON_FINITE_FLOAT` (previously they aborted later with a generic
  `INVALID_RECORD`).
- Strings that are not valid UTF-8 are rejected on write with
  `INVALID_UTF8`.
