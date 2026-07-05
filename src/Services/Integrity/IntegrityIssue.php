<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Integrity;

/**
 * One finding produced by IntegrityValidator or IntegrityRepairer.
 *
 * Fields:
 *  - severity / category — typed tags for filtering and tests.
 *  - tableName — the table the issue belongs to; null for DB-level issues.
 *  - message — short, technical, English, single-line. Human-readable; do
 *              not parse it programmatically — use $context for machine-
 *              readable data.
 *  - context — string-typed key/value pairs the validator attaches for the
 *              repairer's use (e.g. ['index' => 'idx_foo'], ['file' => '...']).
 *  - repaired — true if IntegrityRepairer successfully fixed the issue;
 *               false if the validator only, or if the repair failed.
 *  - repairError — null on success or when repair was not attempted;
 *                  short error message when repair was attempted but failed.
 */
final class IntegrityIssue
{
    /**
     * @param array<string,string> $context
     */
    public function __construct(
        public readonly IssueSeverity $severity,
        public readonly IssueCategory $category,
        public readonly string | null $tableName,
        public readonly string $message,
        public readonly array $context = [],
        public readonly bool $repaired = false,
        public readonly string | null $repairError = null,
    ) {}

    /**
     * Returns a copy of this issue marked as repaired.
     */
    public function withRepaired(): self
    {
        return new self(
            severity: $this->severity,
            category: $this->category,
            tableName: $this->tableName,
            message: $this->message,
            context: $this->context,
            repaired: true,
            repairError: null,
        );
    }

    /**
     * Returns a copy of this issue marked as repair-failed with the given
     * error message.
     */
    public function withRepairError(string $error): self
    {
        return new self(
            severity: $this->severity,
            category: $this->category,
            tableName: $this->tableName,
            message: $this->message,
            context: $this->context,
            repaired: false,
            repairError: $error,
        );
    }
}
