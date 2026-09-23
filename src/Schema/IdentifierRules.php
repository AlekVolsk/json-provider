<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

use AV\JsonProvider\Exception\JsonProviderSchemaException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

/**
 * Naming rules for logical identifiers: table, column and index names.
 *
 * A valid identifier starts with a letter, digit or underscore, continues
 * with letters, digits, underscores or hyphens, and is at most 64 characters
 * long. Dots, path separators and a leading hyphen are impossible by
 * construction, so a validated identifier can never traverse outside the
 * database directory when used as a path segment.
 *
 * Purely numeric identifiers ('0', '42') are rejected: PHP silently casts
 * such array keys to int, so a numeric table or column name would surface
 * as an int wherever the engine iterates a keyed map and crash with a
 * TypeError instead of a provider exception.
 *
 * Index names additionally may not start with the reserved "_fk_" prefix —
 * it is set aside for engine-managed FK backing indexes. The table name
 * "_pendingRename" is reserved: meta.json stores the crash-recovery marker
 * of renameTable under that key, and a table by that name would collide
 * with it.
 *
 * The rules guard the schema boundary (TableSchema / IndexSchema
 * constructors), which covers both the DDL API and loading
 * information_schema.json: hand-editing the schema file is unsupported, so
 * a violation on load signals file corruption, not a migration path.
 */
final class IdentifierRules
{
    public const string SERVICE_INDEX_PREFIX = '_fk_';

    public const string RESERVED_TABLE_NAME = '_pendingRename';

    private const string PATTERN
        = '/^(?!\d+$)[A-Za-z0-9_][A-Za-z0-9_-]{0,63}$/D';

    /**
     * The on-disk form of a table or index name — directory, data, index and
     * lock file names. Always lower case, so the layout is the same on
     * case-sensitive and case-insensitive file systems; two names differing
     * only in case therefore denote the same files and are rejected as
     * duplicates. Identifiers are ASCII, so strtolower is locale-independent.
     */
    public static function physicalName(string $name): string
    {
        return strtolower($name);
    }

    public static function assertTableName(string $name): void
    {
        if (
            preg_match(self::PATTERN, $name) !== 1
            || $name === self::RESERVED_TABLE_NAME
        ) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::InvalidTableName,
                $name,
            );
        }
    }

    public static function assertColumnName(string $name): void
    {
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::InvalidColumnName,
                $name,
            );
        }
    }

    public static function assertIndexName(string $name): void
    {
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::InvalidIndexName,
                $name,
            );
        }

        if (str_starts_with($name, self::SERVICE_INDEX_PREFIX)) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::ReservedIndexName,
                $name,
            );
        }
    }

    /**
     * Engine-managed FK backing indexes live in the reserved namespace:
     * the "_fk_" prefix followed by the FK column name, which itself must
     * be a valid identifier. Only IndexSchema instances flagged isService
     * are validated through here.
     */
    public static function assertServiceIndexName(string $name): void
    {
        if (
            !str_starts_with($name, self::SERVICE_INDEX_PREFIX)
            || preg_match(
                self::PATTERN,
                substr($name, \strlen(self::SERVICE_INDEX_PREFIX)),
            ) !== 1
        ) {
            throw new JsonProviderSchemaException(
                JsonProviderErrorEn::InvalidIndexName,
                $name,
            );
        }
    }

    /**
     * The reserved name of the service index backing FK probes on the
     * given child column.
     */
    public static function serviceIndexNameFor(string $column): string
    {
        return self::SERVICE_INDEX_PREFIX . $column;
    }
}
