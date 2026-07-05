<?php

declare(strict_types=1);

namespace AV\JsonProvider\Mapping;

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

    public function register(DtoMap $map): void
    {
        $this->byTable[$map->table] = $map;
    }

    public function forTable(string $table): DtoMap | null
    {
        return $this->byTable[$table] ?? null;
    }
}
