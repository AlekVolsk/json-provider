<?php

declare(strict_types=1);

namespace AV\JsonProvider\Validation;

/**
 * Parsed form of a column type string: the base type plus the nullable flag.
 *
 * A schema stores types as plain strings (`'int'`, `'datetime|null'`). This
 * splits the optional `|null` suffix once so callers work with a `base` and a
 * `nullable` boolean instead of re-parsing the literal everywhere.
 */
final class ColumnTypeInfo
{
    private const string NULLABLE_SUFFIX = '|null';

    public function __construct(
        public readonly string $base,
        public readonly bool $nullable,
    ) {
    }

    public static function parse(string $type): self
    {
        if (str_ends_with($type, self::NULLABLE_SUFFIX)) {
            return new self(
                substr($type, 0, -\strlen(self::NULLABLE_SUFFIX)),
                true,
            );
        }

        return new self($type, false);
    }

    /**
     * The temporal kind for this base type, or null for non-temporal types
     * (int/string/bool/float and the numeric parts year/month/day).
     */
    public function temporalKind(): TemporalKind | null
    {
        return TemporalKind::tryFromBase($this->base);
    }
}
