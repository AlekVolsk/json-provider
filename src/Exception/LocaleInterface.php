<?php

declare(strict_types=1);

namespace AV\JsonProvider\Exception;

/**
 * Contract for a language enum used to localise provider exception messages.
 * Implemented as a BackedEnum<string>: name = error key, value =
 * translated string.
 * Placeholders are positional %s in argument order.
 */
interface LocaleInterface
{
    /**
     * Returns the localised string for the given key with parameter
     * substitution.
     * If the key is not found in this locale, the implementation should
     * return the key itself —
     * JsonProviderException will then fall back to the English default.
     *
     * @param string ...$params values to substitute for %s placeholders
     */
    public static function translate(string $key, string ...$params): string;
}
