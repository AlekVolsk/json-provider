<?php

declare(strict_types=1);

namespace AV\JsonProvider\Storage;

/**
 * Transaction handle for JsonStorage::transaction().
 *
 * The callback receives current file contents and this handle.
 * To persist changes — call save($next).
 * Without save() the file is left unchanged.
 */
final class JsonStorageTxHandle
{
    private bool $pendingSave = false;

    /** @var array<mixed> */
    private array $pendingData = [];

    /**
     * Schedules the given data to be persisted when the transaction completes.
     * Repeat calls overwrite the previously scheduled payload.
     *
     * @param array<mixed> $data
     */
    public function save(array $data): void
    {
        $this->pendingSave = true;
        $this->pendingData = $data;
    }

    public function hasPendingSave(): bool
    {
        return $this->pendingSave;
    }

    /**
     * @return array<mixed>
     */
    public function pendingData(): array
    {
        return $this->pendingData;
    }
}
