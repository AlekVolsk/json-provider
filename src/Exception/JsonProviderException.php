<?php

declare(strict_types=1);

namespace AV\JsonProvider\Exception;

use AV\JsonProvider\Exception\Locale\LocaleInterface;
use Psr\Log\LoggerInterface;

/**
 * Base class for every exception the provider throws.
 *
 * An exception carries a vocabulary case as its code plus the values to fill
 * the message with. It knows nothing about languages: the invariant message
 * is rendered by the very enum the code belongs to, the localized one by the
 * locale set process-wide, and a locale that does not know the case falls
 * back to the code's own rendering.
 *
 * getMessage()          the invariant message, fixed at construction time
 *                       (for logs and stack traces).
 * getLocalizedMessage() the message in the locale active right now.
 * getErrorKey()         the case name, for handling by string.
 * $error                the case itself, for handling by match.
 *
 * With a logger attached to the provider, every provider exception reports
 * itself at error level the moment it is built — the throw sites stay plain
 * and nothing has to be caught just to be logged.
 */
abstract class JsonProviderException extends \RuntimeException
{
    /** @var null|class-string<LocaleInterface> */
    private static string | null $locale = null;

    private static LoggerInterface | null $logger = null;

    /** @var list<string> */
    private readonly array $params;

    public function __construct(
        public readonly LocaleInterface $error,
        bool | float | int | string | null ...$params,
    ) {
        $this->params = array_values(
            array_map(self::stringify(...), $params),
        );

        parent::__construct($error::translate($error->name, ...$this->params));

        self::$logger?->error($this->getMessage(), $this->getLogContext());
    }

    /**
     * Attaches the logger every provider exception reports itself to.
     * Set by the provider from the logger it was created with.
     */
    public static function setLogger(LoggerInterface | null $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Sets the locale for all provider exceptions.
     * Accepts any enum case implementing LocaleInterface — the vocabulary
     * class is taken from the case.
     */
    public static function setLocale(LocaleInterface $locale): void
    {
        self::$locale = $locale::class;
    }

    /**
     * Drops the locale: messages render in the vocabulary their code belongs
     * to.
     */
    public static function resetLocale(): void
    {
        self::$locale = null;
    }

    /**
     * Returns the message in the current locale, falling back to the
     * invariant one when that locale does not know the case.
     */
    public function getLocalizedMessage(): string
    {
        $locale = self::$locale;

        if ($locale === null) {
            return $this->getMessage();
        }

        $translated = $locale::translate($this->error->name, ...$this->params);

        return $translated === $this->error->name
            ? $this->getMessage()
            : $translated;
    }

    /**
     * Returns the case name — the identifier of the situation, stable across
     * locales.
     */
    public function getErrorKey(): string
    {
        return $this->error->name;
    }

    /**
     * The stack trace with host paths stripped: a frame path is cut back to
     * its "/vendor" segment, so a log line names the package-relative
     * location instead of the deployment layout. A path outside any vendor
     * directory is reported as is.
     */
    public function getSafeTrace(): string
    {
        $frames = [];

        foreach ($this->getTrace() as $depth => $frame) {
            $where = isset($frame['file'])
                ? self::relativePath($frame['file'])
                    . (isset($frame['line']) ? '(' . $frame['line'] . ')' : '')
                : '[internal function]';

            $call = ($frame['class'] ?? '')
                . ($frame['type'] ?? '')
                . $frame['function'];

            $frames[] = '#' . $depth . ' ' . $where . ': ' . $call . '()';
        }

        $frames[] = '#' . \count($frames) . ' {main}';

        return implode("\n", $frames);
    }

    /**
     * PSR-3 context for this exception: the case name to filter by, the
     * throw site and the sanitized trace to read.
     *
     * @return array{errorKey: string, origin: string, trace: string}
     */
    public function getLogContext(): array
    {
        return [
            'errorKey' => $this->getErrorKey(),
            'origin'   => self::relativePath($this->getFile())
                . '(' . $this->getLine() . ')',
            'trace' => $this->getSafeTrace(),
        ];
    }

    /**
     * Renders a parameter as a locale-neutral token: NULL, true and false
     * are format literals, not prose, and a float never picks up a decimal
     * comma.
     */
    private static function stringify(
        bool | float | int | string | null $value,
    ): string {
        return match (true) {
            $value === null  => 'NULL',
            $value === true  => 'true',
            $value === false => 'false',
            default          => (string)$value,
        };
    }

    private static function relativePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $vendor = strpos($normalized, '/vendor/');

        if ($vendor !== false) {
            return substr($normalized, $vendor);
        }

        $src = strrpos($normalized, '/src/');

        return $src === false ? $normalized : substr($normalized, $src);
    }
}
