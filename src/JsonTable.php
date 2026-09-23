<?php

declare(strict_types=1);

namespace AV\JsonProvider;

use AV\JsonProvider\Exception\JsonProviderMappingException;
use AV\JsonProvider\Exception\JsonProviderQueryException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;
use AV\JsonProvider\Mapping\DtoMap;
use AV\JsonProvider\Mapping\DtoMapper;
use AV\JsonProvider\Mapping\MissingPropertyModeEnum;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use AV\JsonProvider\Query\OrderBy;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\TableSchema;

/**
 * QueryBuilder for a single table.
 *
 * Mutable with auto-reset: chain calls (where/orderBy/limit/offset/distinct)
 * accumulate state on the same instance and return `$this`. Any terminal
 * operation (select*, count, exists, insert, update*, delete*) consumes the
 * accumulated state and clears it — including on exception. The same builder
 * can therefore be reused immediately for the next query without leaking
 * leftover filters or ordering.
 *
 * Two record surfaces share one builder, selected by method name:
 *  - object methods (insert/update/selectAll/selectOne) speak DTOs and need a
 *    class registered for the table via JsonDataProvider::registerDto();
 *  - the `*ByArray` twins speak the raw array<string,null|scalar> records and
 *    are always available.
 *
 * For reusable condition sets across multiple queries, build a JsonFilter and
 * apply it via setFilter().
 */
final class JsonTable
{
    private const string CONTEXT_SELECT_COLUMN = 'selectColumn';
    private const string CONTEXT_ORDER_BY = 'orderBy';

    /** @var array<int,FilterCondition> */
    private array $conditions = [];

    /** @var array<int,OrderBy> */
    private array $ordering = [];

    private int | null $limit = null;
    private int $offset = 0;

    /** @var array<int,string> */
    private array $distinctFields = [];

    /**
     * Number of rows affected by the most recent DML operation on this builder.
     * 0 before any DML is run. Read-only operations do not change it.
     */
    private int $affectedRows = 0;

    public function __construct(
        private readonly JsonDataProvider $provider,
        private readonly TableSchema $tableSchema,
        private readonly DtoMap | null $dtoMap,
        private readonly DtoMapper $mapper,
    ) {
    }

    /**
     * Adds a filter condition (AND). An unknown operator string is
     * rejected with the localized INVALID_OPERATOR, which names the table
     * the condition was built against.
     */
    public function where(
        string $field,
        string $operator,
        mixed $value,
        bool $not = false,
    ): self {
        $op = FilterOperatorEnum::tryFrom($operator);

        if ($op === null) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::InvalidOperator,
                $this->tableSchema->name,
                $operator,
            );
        }

        $this->conditions[] = new FilterCondition(
            $field,
            $op,
            $value,
            $not,
        );

        return $this;
    }

    /**
     * Adds a pre-built FilterCondition (for advanced cases).
     */
    public function condition(FilterCondition $condition): self
    {
        $this->conditions[] = $condition;

        return $this;
    }

    /**
     * Replaces the accumulated conditions with the conditions from the
     * given filter.
     * Use to apply a reusable JsonFilter built elsewhere; subsequent
     * where()/condition() calls will append on top of the filter's conditions.
     */
    public function setFilter(JsonFilter $filter): self
    {
        $this->conditions = $filter->getConditions();

        return $this;
    }

    /**
     * Adds a sort rule. The direction is case-insensitive ("asc"/"desc");
     * anything else is rejected — a typo like "descending" must not
     * silently sort ascending.
     */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $dir = SortDirectionEnum::tryFrom(strtolower($direction));

        if ($dir === null) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::InvalidSortDirection,
                $this->tableSchema->name,
                $direction,
            );
        }

        $this->ordering[] = new OrderBy($field, $dir);

        return $this;
    }

    /**
     * Caps the number of returned rows. 0 is valid and yields an empty
     * result (cf. SQL LIMIT 0); negative values are rejected fail-fast.
     */
    public function limit(int $n): self
    {
        if ($n < 0) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::InvalidLimit,
                $this->tableSchema->name,
                $n,
            );
        }

        $this->limit = $n;

        return $this;
    }

    /**
     * Skips the given number of rows. Negative values are rejected
     * fail-fast.
     */
    public function offset(int $n): self
    {
        if ($n < 0) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::InvalidOffset,
                $this->tableSchema->name,
                $n,
            );
        }

        $this->offset = $n;

        return $this;
    }

    /**
     * Enables deduplication by the given fields.
     * Applied after filtering and sorting, before pagination: every group
     * of duplicates keeps its first row in result order, so the ordering
     * decides which one survives.
     */
    public function distinct(string ...$fields): self
    {
        $this->distinctFields = array_values($fields);

        return $this;
    }

    /**
     * Returns all matching records as DTOs, hydrated lazily (single-pass
     * generator; use iterator_to_array() if you need a re-iterable list).
     *
     * @return iterable<int,object>
     */
    public function selectAll(): iterable
    {
        $map = $this->requireDtoMap();

        try {
            $rows = $this->provider->select(
                $this->tableSchema->name,
                $this->conditions,
                $this->ordering,
                $this->limit,
                $this->offset,
                $this->distinctFields,
            );
        } finally {
            $this->resetState();
        }

        return $this->hydrateAll($map, $rows);
    }

    /**
     * Returns all matching records as raw arrays.
     *
     * @return array<int,array<string,null|scalar>>
     */
    public function selectAllByArray(): array
    {
        try {
            return $this->provider->select(
                $this->tableSchema->name,
                $this->conditions,
                $this->ordering,
                $this->limit,
                $this->offset,
                $this->distinctFields,
            );
        } finally {
            $this->resetState();
        }
    }

    /**
     * Returns the first matching record as a DTO, or null.
     */
    public function selectOne(): object | null
    {
        $map = $this->requireDtoMap();
        $row = $this->firstRow();

        return $row === null ? null : $this->mapper->hydrate($map, $row);
    }

    /**
     * Returns the first matching record as a raw array, or null.
     *
     * @return null|array<string,null|scalar>
     */
    public function selectOneByArray(): array | null
    {
        return $this->firstRow();
    }

    /**
     * Returns a list of values for a single field matching the current
     * conditions.
     *
     * @return array<int,null|scalar>
     */
    public function selectColumn(string $field): array
    {
        try {
            if (!\array_key_exists($field, $this->tableSchema->columns)) {
                throw new JsonProviderQueryException(
                    JsonProviderErrorEn::QueryUnknownColumn,
                    $this->tableSchema->name,
                    $field,
                    self::CONTEXT_SELECT_COLUMN,
                );
            }

            $records = $this->provider->select(
                $this->tableSchema->name,
                $this->conditions,
                $this->ordering,
                $this->limit,
                $this->offset,
                $this->distinctFields,
            );

            return array_values(array_map(
                static fn (array $r): mixed => $r[$field] ?? null,
                $records,
            ));
        } finally {
            $this->resetState();
        }
    }

    /**
     * Returns the count of matching records. Ordering does not change a
     * count, but an unknown orderBy column is still rejected — the same
     * builder chain must not silently pass here and throw on select.
     */
    public function count(): int
    {
        try {
            foreach ($this->ordering as $order) {
                if (
                    !\array_key_exists(
                        $order->field,
                        $this->tableSchema->columns,
                    )
                ) {
                    throw new JsonProviderQueryException(
                        JsonProviderErrorEn::QueryUnknownColumn,
                        $this->tableSchema->name,
                        $order->field,
                        self::CONTEXT_ORDER_BY,
                    );
                }
            }

            if ($this->distinctFields === []) {
                return $this->provider->count(
                    $this->tableSchema->name,
                    $this->conditions,
                );
            }

            return \count($this->provider->select(
                $this->tableSchema->name,
                $this->conditions,
                [],
                null,
                0,
                $this->distinctFields,
            ));
        } finally {
            $this->resetState();
        }
    }

    /**
     * Returns whether at least one matching record exists.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Inserts a DTO; returns the assigned id. affectedRows becomes 1.
     */
    public function insert(object $dto): int
    {
        $map = $this->requireDtoMap();

        try {
            $id = $this->provider->insert(
                $this->tableSchema->name,
                $this->mapper->extract($map, $dto),
            );
            $this->affectedRows = 1;

            return $id;
        } finally {
            $this->resetState();
        }
    }

    /**
     * Inserts a raw record; returns the assigned id. affectedRows becomes 1.
     *
     * @param array<string,null|scalar> $record
     */
    public function insertByArray(array $record): int
    {
        try {
            $id = $this->provider->insert($this->tableSchema->name, $record);
            $this->affectedRows = 1;

            return $id;
        } finally {
            $this->resetState();
        }
    }

    /**
     * Overwrites the row identified by the DTO's own id with all of the DTO's
     * fields. Accumulated where() conditions are ignored — the id drives it.
     * A mapped property the object does not carry is written as null or
     * leaves the column untouched, per $missing.
     * Returns true on success (including no matching row, affectedRows 0).
     */
    public function update(
        object $dto,
        MissingPropertyModeEnum $missing = MissingPropertyModeEnum::WriteNull,
    ): bool {
        $map = $this->requireDtoMap();

        try {
            $record = $this->mapper->extract($map, $dto, $missing);
            $id = $record['id'] ?? null;

            if (!\is_int($id)) {
                throw new JsonProviderMappingException(
                    JsonProviderErrorEn::DtoIdMustBeInt,
                    $this->tableSchema->name,
                );
            }

            $this->affectedRows = $this->provider->update(
                $this->tableSchema->name,
                [new FilterCondition('id', FilterOperatorEnum::EQ, $id)],
                $record,
            );

            return true;
        } finally {
            $this->resetState();
        }
    }

    /**
     * Updates all records matching the current conditions with the same
     * data patch. Returns true on success (empty match set → affectedRows 0).
     *
     * @param array<string,null|scalar> $data
     */
    public function updateByArray(array $data): bool
    {
        try {
            $this->affectedRows = $this->provider->update(
                $this->tableSchema->name,
                $this->conditions,
                $data,
            );

            return true;
        } finally {
            $this->resetState();
        }
    }

    /**
     * Updates a single record by id with a raw patch. Convenience wrapper
     * around updateByArray().
     *
     * @param array<string,null|scalar> $data
     */
    public function updateByIdByArray(int $id, array $data): bool
    {
        return $this->where('id', '=', $id)->updateByArray($data);
    }

    /**
     * Deletes records matching the current conditions. Returns true on
     * successful operation (including empty match set, where
     * affectedRows becomes 0).
     */
    public function delete(): bool
    {
        try {
            $this->affectedRows = $this->provider->delete(
                $this->tableSchema->name,
                $this->conditions,
            );

            return true;
        } finally {
            $this->resetState();
        }
    }

    /**
     * Deletes a single record by id. Convenience wrapper around delete().
     */
    public function deleteById(int $id): bool
    {
        return $this->where('id', '=', $id)->delete();
    }

    /**
     * Returns the number of rows affected by the most recent DML on this
     * builder. 0 if no DML was run yet. Not affected by read operations.
     */
    public function affectedRows(): int
    {
        return $this->affectedRows;
    }

    /**
     * Returns the most recently allocated auto-increment id for the table.
     * 0 on an empty table. Not rolled back on delete (ids are never reused;
     * gaps in the sequence are normal).
     * O(1) — reads meta.json.
     * Independent of accumulated query state — does not read or reset it.
     */
    public function getLastInsertedId(): int
    {
        return $this->provider->getLastInsertedId($this->tableSchema->name);
    }

    /**
     * Returns the id that WILL be allocated to the next insert
     * (lastInsertedId + 1).
     * Does not reserve the id — a parallel insert may consume the value.
     * O(1) — reads meta.json.
     * Independent of accumulated query state — does not read or reset it.
     */
    public function getNextId(): int
    {
        return $this->provider->getNextId($this->tableSchema->name);
    }

    /**
     * Reorders columns of the underlying table.
     *
     * Meta-operation: does not consume or reset accumulated query state
     * and does not touch affectedRows. See JsonDataProvider::reorderColumns
     * for the full contract.
     *
     * @param array<int,string> $newOrder
     */
    public function reorderColumns(array $newOrder): void
    {
        $this->provider->reorderColumns($this->tableSchema->name, $newOrder);
    }

    /**
     * Rebuilds a single named index of the underlying table from current data.
     *
     * Meta-operation: does not consume or reset accumulated query state and
     * does not touch affectedRows. See JsonDataProvider::rebuildIndex for the
     * full contract.
     */
    public function rebuildIndex(string $indexName): void
    {
        $this->provider->rebuildIndex($this->tableSchema->name, $indexName);
    }

    /**
     * Rebuilds every index of the underlying table (including PK) from
     * current data.
     *
     * Meta-operation: does not consume or reset accumulated query state and
     * does not touch affectedRows.
     */
    public function rebuildAllIndexes(): void
    {
        $this->provider->rebuildAllIndexes($this->tableSchema->name);
    }

    /**
     * Heavy-weight preventive optimization for the underlying table.
     * Sorts records by id ASC and rebuilds every index.
     *
     * Meta-operation: does not consume or reset accumulated query state and
     * does not touch affectedRows. See JsonDataProvider::optimizeTable.
     */
    public function optimizeTable(): void
    {
        $this->provider->optimizeTable($this->tableSchema->name);
    }

    /**
     * Runs the current query for a single row and resets state.
     *
     * @return null|array<string,null|scalar>
     */
    private function firstRow(): array | null
    {
        try {
            $limit = $this->limit === null ? 1 : min(1, $this->limit);

            $results = $this->provider->select(
                $this->tableSchema->name,
                $this->conditions,
                $this->ordering,
                $limit,
                $this->offset,
                $this->distinctFields,
            );

            return $results[0] ?? null;
        } finally {
            $this->resetState();
        }
    }

    /**
     * Lazily hydrates already-fetched rows into DTOs.
     *
     * @param array<int,array<string,null|scalar>> $rows
     *
     * @return \Generator<int,object>
     */
    private function hydrateAll(DtoMap $map, array $rows): \Generator
    {
        foreach ($rows as $row) {
            yield $this->mapper->hydrate($map, $row);
        }
    }

    /**
     * Called first by every DTO terminal operation, before its own
     * try/finally, so the failure path resets the query state itself.
     */
    private function requireDtoMap(): DtoMap
    {
        if ($this->dtoMap === null) {
            $this->resetState();

            throw new JsonProviderMappingException(
                JsonProviderErrorEn::DtoNotRegistered,
                $this->tableSchema->name,
            );
        }

        return $this->dtoMap;
    }

    /**
     * Clears the accumulated query state. Called automatically by every
     * terminal operation (including on exception via finally). Does not
     * touch affectedRows.
     */
    private function resetState(): void
    {
        $this->conditions = [];
        $this->ordering = [];
        $this->limit = null;
        $this->offset = 0;
        $this->distinctFields = [];
    }
}
