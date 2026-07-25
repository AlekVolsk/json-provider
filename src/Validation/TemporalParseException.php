<?php

declare(strict_types=1);

namespace AV\JsonProvider\Validation;

/**
 * Internal signal raised by TemporalCodec::encode() when a value cannot be
 * accepted. Carries just enough to let ValueValidator raise a localized,
 * table/column-aware JsonProviderException — this type never escapes
 * the package.
 *
 * `zeroDate` distinguishes the explicit "0000-00-00" family (a dedicated error)
 * from a general format/overflow rejection. `fractionUnsupported` flags a
 * sub-second precision the column kind does not accept (a fraction on a
 * second-resolution kind, or more than millisecond precision on a `*z` kind)
 * so ValueValidator can raise the dedicated TEMPORAL_FRACTION_UNSUPPORTED
 * error instead of the generic one.
 */
final class TemporalParseException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $zeroDate = false,
        public readonly bool $fractionUnsupported = false,
    ) {
        parent::__construct($message);
    }
}
