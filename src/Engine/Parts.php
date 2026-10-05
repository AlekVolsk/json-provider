<?php

declare(strict_types=1);

namespace AV\JsonProvider\Engine;

/**
 * The engine parts behind one provider instance, each built on first use
 * together with the parts it stands on: opening a database builds none of
 * them.
 *
 * @internal
 */
final class Parts
{
    private RecordMatcher | null $matcher = null;
    private TableStore | null $store = null;
    private IndexPlanner | null $planner = null;
    private IndexReader | null $indexReader = null;
    private TableReader | null $reader = null;
    private TableWriter | null $writer = null;
    private IndexChanges | null $indexChanges = null;
    private SchemaChanges | null $schemaChanges = null;
    private Maintenance | null $maintenance = null;

    public function __construct(private readonly Context $context)
    {
    }

    public function store(): TableStore
    {
        return $this->store ??= new TableStore($this->context);
    }

    public function reader(): TableReader
    {
        return $this->reader ??= new TableReader(
            $this->context,
            $this->matcher(),
            $this->store(),
            $this->planner(),
            $this->indexReader(),
        );
    }

    public function writer(): TableWriter
    {
        return $this->writer ??= new TableWriter(
            $this->context,
            $this->matcher(),
            $this->store(),
            $this->indexReader(),
        );
    }

    public function indexChanges(): IndexChanges
    {
        return $this->indexChanges ??= new IndexChanges(
            $this->context,
            $this->store(),
        );
    }

    public function schemaChanges(): SchemaChanges
    {
        return $this->schemaChanges ??= new SchemaChanges(
            $this->context,
            $this->store(),
            $this->indexChanges(),
        );
    }

    public function maintenance(): Maintenance
    {
        return $this->maintenance ??= new Maintenance(
            $this->context,
            $this->store(),
        );
    }

    private function matcher(): RecordMatcher
    {
        return $this->matcher ??= new RecordMatcher($this->context);
    }

    private function planner(): IndexPlanner
    {
        return $this->planner ??= new IndexPlanner($this->context);
    }

    private function indexReader(): IndexReader
    {
        return $this->indexReader ??= new IndexReader(
            $this->context,
            $this->matcher(),
            $this->store(),
            $this->planner(),
        );
    }
}
