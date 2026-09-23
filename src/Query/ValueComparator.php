<?php

declare(strict_types=1);

namespace AV\JsonProvider\Query;

/**
 * The single ordering comparator for record values: null sorts first,
 * numbers compare numerically, strings bytewise (or via the intl Collator
 * in Locale mode), mixed scalar pairs by string cast.
 *
 * Used by the range filter operators and ORDER BY. Equality operators
 * (EQ/IN) do not go through here — they stay strict `===`.
 */
final class ValueComparator
{
    private static \Collator | null $collator = null;

    private static string | null $collatorLocale = null;

    private function __construct()
    {
    }

    public static function compare(
        bool | float | int | string | null $a,
        bool | float | int | string | null $b,
        ComparisonModeEnum $mode = ComparisonModeEnum::Binary,
    ): int {
        if ($a === $b) {
            return 0;
        }

        if ($a === null) {
            return -1;
        }

        if ($b === null) {
            return 1;
        }

        if (\is_string($a) && \is_string($b)) {
            if ($mode === ComparisonModeEnum::Locale) {
                $result = self::localeCompare($a, $b);

                if ($result !== null) {
                    return $result;
                }
            }

            return strcmp($a, $b);
        }

        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            return $a <=> $b;
        }

        return strcmp((string)$a, (string)$b);
    }

    /**
     * Collator comparison, or null when ext-intl is unavailable or the
     * collator refuses the input — the caller falls back to Binary
     * silently (chosen contract: Locale is best-effort, not a hard
     * dependency).
     */
    private static function localeCompare(string $a, string $b): int | null
    {
        if (!\extension_loaded('intl')) {
            return null;
        }

        $locale = \Locale::getDefault();

        if (self::$collator === null || self::$collatorLocale !== $locale) {
            self::$collator = new \Collator($locale);
            self::$collatorLocale = $locale;
        }

        $result = self::$collator->compare($a, $b);

        return $result === false ? null : $result;
    }
}
