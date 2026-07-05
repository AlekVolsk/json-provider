<?php

declare(strict_types=1);

namespace AV\JsonProvider\Schema;

/**
 * Describes a relation between two tables.
 * Parsed from information_schema.json.
 */
final class RelationSchema
{
    /**
     * @param string $fromTable  source table (holds the foreignKey for
     *                           belongsTo)
     * @param string $foreignKey field in fromTable that is the foreign key
     * @param string $toTable    target table
     * @param string $references field in toTable referenced by foreignKey
     *                           (usually 'id')
     */
    public function __construct(
        public readonly string $fromTable,
        public readonly string $foreignKey,
        public readonly string $toTable,
        public readonly string $references,
        public readonly RelationTypeEnum $type,
        // phpcs:disable Generic.Files.LineLength
        public readonly ForeignKeyActionEnum $onDelete = ForeignKeyActionEnum::NO_ACTION,
        public readonly ForeignKeyActionEnum $onUpdate = ForeignKeyActionEnum::NO_ACTION,
        // phpcs:enable
    ) {}
}
