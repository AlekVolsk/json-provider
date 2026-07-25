<?php

declare(strict_types=1);

namespace AV\JsonProvider\Mapping;

use AV\JsonProvider\Exception\JsonProviderMappingException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

/**
 * Holds the compiled DTO map for each table that has one bound.
 *
 * Populated once at startup via JsonDataProvider::registerDto(); the query
 * builder looks a table up here to decide whether its object methods are
 * available.
 */
final class DtoRegistry
{
    /** @var array<string,DtoMap> */
    private array $byTable = [];

    /**
     * Binds a compiled DTO map to its table. Re-registering the SAME class is
     * an idempotent no-op; binding a DIFFERENT class to a table that already
     * has one throws DTO_ALREADY_REGISTERED — swap it out through
     * JsonDataProvider::unregisterDto() first, so a table can never silently
     * serve two conflicting shapes.
     */
    public function register(DtoMap $map): void
    {
        $existing = $this->byTable[$map->table] ?? null;

        if ($existing !== null && $existing->class !== $map->class) {
            throw new JsonProviderMappingException(
                JsonProviderErrorEn::DtoAlreadyRegistered,
                $map->table,
                $existing->class,
                $map->class,
            );
        }

        $this->byTable[$map->table] = $map;
    }

    /**
     * Removes the compiled DTO map bound to a table, if any. Idempotent — an
     * unbound table is a no-op. Called by the public unregisterDto(), and
     * whenever a table is dropped, renamed or its DTO no longer matches the
     * schema, so a later re-create never reuses a stale mapping.
     */
    public function unregister(string $table): void
    {
        unset($this->byTable[$table]);
    }

    public function forTable(string $table): DtoMap | null
    {
        return $this->byTable[$table] ?? null;
    }
}
