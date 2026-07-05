<?php

declare(strict_types=1);

namespace AV\JsonProvider\Exception;

/**
 * Base exception class for all provider errors.
 * Stores the error key and parameters for localised messages.
 * Locale is set statically via setLocale() — once per process.
 *
 * getMessage()          always returns the English message (for logs and
 *                        stack traces).
 * getLocalizedMessage() returns the message in the current locale.
 */
class JsonProviderException extends \RuntimeException
{
    /** @var class-string<LocaleInterface> */
    private static string $localeClass = LangEnEnum::class;

    /** @var array<int,string> */
    private readonly array $params;

    public function __construct(
        private readonly string $errorKey,
        string ...$params,
    ) {
        $this->params = array_values($params);
        parent::__construct(LangEnEnum::translate($errorKey, ...$this->params));
    }

    /**
     * Sets the locale for all provider exceptions.
     * Accepts any enum case implementing LocaleInterface — the class is
     * resolved automatically.
     */
    public static function setLocale(LocaleInterface $locale): void
    {
        self::$localeClass = $locale::class;
    }

    /**
     * Resets the locale to English (default).
     */
    public static function resetLocale(): void
    {
        self::$localeClass = LangEnEnum::class;
    }

    /**
     * Returns the message in the current locale.
     * Falls back to English if the key is not found in the active locale.
     */
    public function getLocalizedMessage(): string
    {
        $class = self::$localeClass;

        if ($class === LangEnEnum::class) {
            return $this->getMessage();
        }

        $translated = $class::translate($this->errorKey, ...$this->params);

        if ($translated === $this->errorKey) {
            return LangEnEnum::translate($this->errorKey, ...$this->params);
        }

        return $translated;
    }

    /**
     * Returns the error key for programmatic exception handling.
     */
    public function getErrorKey(): string
    {
        return $this->errorKey;
    }
}
