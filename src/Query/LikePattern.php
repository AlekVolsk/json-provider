<?php

declare(strict_types=1);

namespace AV\JsonProvider\Query;

use AV\JsonProvider\Exception\JsonProviderQueryException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

/**
 * A LIKE pattern parsed once, then matched against any number of values.
 *
 * Bytewise and case-sensitive. An unescaped `%` is the only wildcard and
 * matches any byte run, consecutive ones collapse into one; a backslash
 * escapes the next byte, a trailing backslash is a literal one; `_` is a
 * literal underscore. The shapes `abc`, `abc%`, `%abc`, `%abc%` and `%`
 * are answered by plain string functions; any other is one anchored
 * regular expression built here.
 */
final class LikePattern
{
    private const int EXACT = 0;
    private const int PREFIX = 1;
    private const int SUFFIX = 2;
    private const int CONTAINS = 3;
    private const int ANY = 4;
    private const int REGEX = 5;

    private function __construct(
        private readonly string $pattern,
        private readonly int $shape,
        private readonly string $literal,
        private readonly string $regex,
    ) {
    }

    public static function compile(string $pattern): self
    {
        $parts = [''];
        $regex = '';
        $last = 0;
        $len = \strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $len) {
                $i++;
                $parts[$last] .= $pattern[$i];
                $regex .= preg_quote($pattern[$i], '/');

                continue;
            }

            if ($char === '%') {
                if ($parts[$last] !== '' || $last === 0) {
                    $parts[] = '';
                    $last++;
                    $regex .= '.*';
                }

                continue;
            }

            $parts[$last] .= $char;
            $regex .= preg_quote($char, '/');
        }

        $first = $parts[0];
        $end = $parts[$last];
        $shape = self::REGEX;
        $literal = '';

        if ($last === 0) {
            [$shape, $literal] = [self::EXACT, $first];
        } elseif ($last === 1 && $first === '' && $end === '') {
            $shape = self::ANY;
        } elseif ($last === 1 && $end === '') {
            [$shape, $literal] = [self::PREFIX, $first];
        } elseif ($last === 1 && $first === '') {
            [$shape, $literal] = [self::SUFFIX, $end];
        } elseif ($last === 2 && $first === '' && $end === '') {
            [$shape, $literal] = [self::CONTAINS, $parts[1]];
        }

        return new self($pattern, $shape, $literal, '/^' . $regex . '$/sD');
    }

    /**
     * A test of one record field against the pattern, specialised to the
     * pattern's shape: a non-string value never matches.
     *
     * @return \Closure(array<mixed>): bool
     */
    public function predicate(string $field): \Closure
    {
        $literal = $this->literal;

        return match ($this->shape) {
            self::EXACT => static fn (array $r): bool => ($r[$field] ?? null)
                === $literal,
            self::PREFIX => static fn (array $r): bool => \is_string(
                $value = $r[$field] ?? null,
            ) && str_starts_with($value, $literal),
            self::SUFFIX => static fn (array $r): bool => \is_string(
                $value = $r[$field] ?? null,
            ) && str_ends_with($value, $literal),
            self::CONTAINS => static fn (array $r): bool => \is_string(
                $value = $r[$field] ?? null,
            ) && str_contains($value, $literal),
            self::ANY => static fn (array $r): bool => \is_string(
                $r[$field] ?? null,
            ),
            default => fn (array $r): bool => \is_string(
                $value = $r[$field] ?? null,
            ) && $this->matchesRegex($value),
        };
    }

    public function matches(string $value): bool
    {
        return match ($this->shape) {
            self::EXACT    => $value === $this->literal,
            self::PREFIX   => str_starts_with($value, $this->literal),
            self::SUFFIX   => str_ends_with($value, $this->literal),
            self::CONTAINS => str_contains($value, $this->literal),
            self::ANY      => true,
            default        => $this->matchesRegex($value),
        };
    }

    private function matchesRegex(string $value): bool
    {
        $result = preg_match($this->regex, $value);

        if ($result === false) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::LikeEvaluationFailed,
                $this->pattern,
                preg_last_error_msg(),
            );
        }

        return $result === 1;
    }
}
