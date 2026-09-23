<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Integrity;

/**
 * The output of IntegrityValidator / IntegrityRepairer.
 *
 * Contains a typed list of issues plus aggregate counters. Issues are
 * ordered critical-first by IssueSeverityEnum::rank() (emission order is kept
 * within one severity). The format() method produces a human-readable
 * plain-text report (English, technical, fixed-width).
 */
final class IntegrityReport
{
    /** @var array<int,IntegrityIssue> */
    public readonly array $issues;

    /**
     * @param array<int,IntegrityIssue> $issues
     */
    public function __construct(
        array $issues,
        public readonly int $tablesChecked,
        public readonly int $tablesRepaired,
        public readonly float $durationSeconds,
    ) {
        usort(
            $issues,
            static fn (IntegrityIssue $a, IntegrityIssue $b): int => $a
                ->severity->rank() <=> $b->severity->rank(),
        );
        $this->issues = $issues;
    }

    /**
     * Whether the report holds any issue of ERROR severity or worse
     * (CRITICAL counts as an error).
     */
    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if (
                $issue->severity->rank() <= IssueSeverityEnum::ERROR->rank()
            ) {
                return true;
            }
        }

        return false;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === IssueSeverityEnum::WARNING) {
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
    public function issuesBySeverity(IssueSeverityEnum $severity): array
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
    public function issuesByCategory(IssueCategoryEnum $category): array
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
