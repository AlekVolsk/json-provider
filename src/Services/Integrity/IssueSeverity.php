<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Integrity;

/**
 * Severity levels for IntegrityIssue.
 *
 *  - error:   the storage is in a broken state; reads or writes may fail or
 *             return wrong data until the issue is repaired.
 *  - warning: the storage is internally consistent, but a non-critical
 *             deviation is present (e.g. orphan file taking up space).
 *  - info:    purely informational (e.g. table optimized successfully).
 */
enum IssueSeverity: string
{
    case ERROR = 'error';
    case WARNING = 'warning';
    case INFO = 'info';
}
