<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Integrity;

/**
 * The output of IntegrityValidator / IntegrityRepairer.
 *
 * Contains a typed list of issues plus aggregate counters. The format() method
 * produces a human-readable plain-text report (English, technical,
 * fixed-width).
 */
final class IntegrityReport
{
    /**
     * @param array<int,IntegrityIssue> $issues
     */
    public function __construct(
        public readonly array $issues,
        public readonly int $tablesChecked,
        public readonly int $tablesRepaired,
        public readonly float $durationSeconds,
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === IssueSeverity::ERROR) {
                return true;
            }
        }

        return false;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === IssueSeverity::WARNING) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns issues filtered by severity.
     *
     * @return array<int,IntegrityIssue>
     */
    public function issuesBySeverity(IssueSeverity $severity): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (IntegrityIssue $i): bool => $i->severity === $severity,
        ));
    }

    /**
     * Returns issues filtered by category.
     *
     * @return array<int,IntegrityIssue>
     */
    public function issuesByCategory(IssueCategory $category): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (IntegrityIssue $i): bool => $i->category === $category,
        ));
    }

    /**
     * Renders the report as a plain-text block suitable for logs or human
     * review.
     * Format is fixed-width, English, technical.
     */
    public function format(): string
    {
        $lines = [];

        $lines[] = \sprintf(
            'JsonProvider integrity report — tables checked: %d, '
                . 'repaired: %d, duration: %.3fs',
            $this->tablesChecked,
            $this->tablesRepaired,
            $this->durationSeconds,
        );

        if ($this->issues === []) {
            $lines[] = 'No issues found.';

            return implode("\n", $lines) . "\n";
        }

        foreach ($this->issues as $issue) {
            $severity = str_pad(
                '[' . strtoupper($issue->severity->value) . ']',
                9,
            );
            $category = str_pad($issue->category->value, 26);
            $context = $issue->tableName !== null
                ? str_pad('table=' . $issue->tableName, 26)
                : str_pad('db', 26);

            $suffix = '';

            if ($issue->repaired) {
                $suffix = ' — REPAIRED';
            } elseif ($issue->repairError !== null) {
                $suffix = ' — REPAIR FAILED: ' . $issue->repairError;
            }

            $lines[] = $severity . ' ' . $category . ' ' . $context
                . ' ' . $issue->message . $suffix;
        }

        return implode("\n", $lines) . "\n";
    }
}
