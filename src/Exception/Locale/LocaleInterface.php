<?php

declare(strict_types=1);

namespace AV\JsonProvider\Exception\Locale;

/**
 * Contract for a language enum holding the provider's error vocabulary.
 * Implemented as a BackedEnum<string>: name = situation identifier, value =
 * message template. Placeholders are positional %s in argument order.
 *
 * Extending \UnitEnum keeps the contract enum-only and gives the exception
 * a typed case name to look the message up by.
 */
interface LocaleInterface extends \UnitEnum
{
    /**
     * Returns the message for the given case name with the parameters
     * substituted.
     * If this locale does not know the name, the implementation returns the
     * name itself — the exception then falls back to the vocabulary its
     * code belongs to.
     *
     * @param string ...$params values to substitute for %s placeholders
     */
    public static function translate(string $key, string ...$params): string;
}
