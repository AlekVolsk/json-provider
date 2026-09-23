<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Integrity;

use Psr\Log\LogLevel;

/**
 * Severity levels for IntegrityIssue — one unified scale shared by the
 * validator report and the optional PSR-3 logger.
 *
 *  - critical: working with the database is impossible — the issue breaks
 *              reads or writes even at the full-scan level (data file
 *              gone, no meta entry to allocate ids from).
 *  - error:    a SCHEMA-level structure is broken, but full-scan reads
 *              and writes still work (index files corrupt or drifted,
 *              stale counters, half-finished rename).
 *  - warning:  relations or data typing do not match the schema (orphan
 *              FK values under an enforced action, unique duplicates,
 *              present nulls in non-nullable columns, unparseable lines).
 *  - info:     a data-only observation with no schema involved (orphan FK
 *              values under noAction, successful optimization).
 */
enum IssueSeverityEnum: string
{
    case CRITICAL = 'critical';
    case ERROR = 'error';
    case WARNING = 'warning';
    case INFO = 'info';

    /**
     * The PSR-3 log level this severity maps to.
     */
    public function psrLevel(): string
    {
        return match ($this) {
            self::CRITICAL => LogLevel::CRITICAL,
            self::ERROR    => LogLevel::ERROR,
            self::WARNING  => LogLevel::WARNING,
            self::INFO     => LogLevel::INFO,
        };
    }

    /**
     * Sort rank for critical-first report ordering (CRITICAL=0 .. INFO=3).
     */
    public function rank(): int
    {
        return match ($this) {
            self::CRITICAL => 0,
            self::ERROR    => 1,
            self::WARNING  => 2,
            self::INFO     => 3,
        };
    }
}
