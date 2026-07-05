<?php

declare(strict_types=1);

namespace AV\JsonProvider\Validation;

/**
 * Internal signal raised by TemporalCodec::encode() when a value cannot be
 * accepted. Carries just enough to let ValueValidator raise a localized,
 * table/column-aware StorageException — this type never escapes the package.
 *
 * `zeroDate` distinguishes the explicit "0000-00-00" family (a dedicated error)
 * from a general format/overflow rejection.
 */
final class TemporalParseException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $zeroDate = false,
    ) {
        parent::__construct($message);
    }
}
