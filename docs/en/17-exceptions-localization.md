# Exceptions and localization

Every provider exception descends from `AV\JsonProvider\Exception\JsonProviderException`, an abstract class that is never thrown itself. What gets thrown is one of nine domain classes: you pick how coarsely to catch by picking which one you catch.

| Class | Domain |
| - | - |
| `JsonProviderSchemaException` | schema structure, DDL contracts, identifiers, the primary key contract, column migrations |
| `JsonProviderTableException` | lifecycle of a table and of the database |
| `JsonProviderQueryException` | building a query or a filter |
| `JsonProviderDataException` | values and records |
| `JsonProviderRelationException` | foreign keys, as declared and as enforced |
| `JsonProviderMappingException` | binding a record object to a table |
| `JsonProviderServiceException` | backups, integrity, the meta file, cache adapters |
| `JsonProviderIoException` | files and JSON |
| `JsonProviderLockException` | locking |

## Handling an error

```php
use AV\JsonProvider\Exception\JsonProviderDataException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

try {
    $db->table('users')->insertByArray(['email' => $existing]);
} catch (JsonProviderDataException $e) {
    $e->error;                  // the vocabulary case — match on it, no magic strings
    $e->getMessage();           // the invariant (English) message, for logs
    $e->getLocalizedMessage();  // the message in the active locale, for people
    $e->getErrorKey();          // the case name as a string, for logs and metrics

    match ($e->error) {
        JsonProviderErrorEn::UniqueViolation => $this->reportDuplicate($e),
        default => throw $e,
    };
}
```

The identifier of a situation is a case of `JsonProviderErrorEn`, and it does not depend on the locale in force: a throw site always names the same enum, and the locale only affects the text.

## Messages

`getMessage()` is fixed when the exception is built and stays English — a log line must not depend on the locale the application happened to set. `getLocalizedMessage()` is computed per call and follows the active locale; a case that locale does not know renders in English as a whole sentence.

## Setting a locale

```php
use AV\JsonProvider\Exception\Locale\JsonProviderErrorRu;

$db->setLocale(JsonProviderErrorRu::TableNotFound);  // any case will do — only the class matters
$db->resetLocale();                                  // back to English
```

The locale applies process-wide, not per provider instance.

## Custom locale

A locale is one enum per language implementing `LocaleInterface`. Case names match `JsonProviderErrorEn`; the value is the message template with positional `%s`:

```php
use AV\JsonProvider\Exception\Locale\LocaleInterface;

enum JsonProviderErrorDe: string implements LocaleInterface
{
    case TableNotFound = 'Tabelle "%s" nicht im Schema gefunden';
    case UniqueViolation = 'Eindeutigkeitsverletzung in "%s" auf Feldern [%s]: %s';

    public static function translate(string $key, string ...$params): string
    {
        foreach (self::cases() as $case) {
            if ($case->name === $key) {
                return $params !== []
                    ? \sprintf($case->value, ...$params)
                    : $case->value;
            }
        }

        return $key;   // an unknown case — the provider falls back to English
    }
}

$db->setLocale(JsonProviderErrorDe::TableNotFound);
```

The locale class can live in any namespace. Translating the whole vocabulary up front is not required: an untranslated case renders in English as a whole, never blended with translated text inside one sentence.

Template text is plain language: no method names, no internal shorthand, so it can be translated by someone who does not know how the package is built. Only these stay verbatim: keys of the JSON files (`"tables"`, `"fields"`), the values the schema accepts (`noAction`, `cascade`, `setNull`, `restrict`, `asc`, `desc`), column type names, attribute names and language literals.

## Logging

When the provider is created with a PSR-3 logger, **every** provider exception reports itself at error level the moment it is raised — nothing has to be caught to be logged:

```php
$db = JsonDataProvider::getInstance($path, $cache, $logger);
```

The log line carries the invariant message plus context:

| Context key | What it holds |
| - | - |
| `errorKey` | the case name — filter and count errors by it |
| `origin` | the throw site, `file(line)` |
| `trace` | the call stack |

Paths in `origin` and `trace` are trimmed: everything before the `/vendor` segment is dropped, so a log line names the place inside the package instead of the machine's layout.

## The vocabulary

177 cases; the case name is the identifier and the English text lives in the case itself (`JsonProviderErrorEn::TableNotFound->value`).
