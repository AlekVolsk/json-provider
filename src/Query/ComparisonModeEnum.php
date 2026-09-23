<?php

declare(strict_types=1);

namespace AV\JsonProvider\Query;

/**
 * String comparison mode for ordering operators (GT/GTE/LT/LTE/BETWEEN)
 * and ORDER BY.
 *
 * Binary (default): bytewise strcmp — deterministic, dependency-free, and
 * exactly matches the byte-encoded index order, so string ranges can be
 * served by indexes.
 *
 * Locale: ext-intl Collator for string pairs — natural-language ordering.
 * Affects ORDER only: EQ/IN/LIKE stay exact byte comparisons and remain
 * indexable. Indexes are not used for string ordering/ranges in this mode
 * (the byte-ordered index disagrees with the collator); the engine falls
 * back to a full scan. Without ext-intl the mode silently degrades to
 * Binary.
 */
enum ComparisonModeEnum
{
    case Binary;
    case Locale;
}
