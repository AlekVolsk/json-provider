<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Query\ComparisonModeEnum;
use AV\JsonProvider\Query\FilterCondition;
use AV\JsonProvider\Query\FilterOperatorEnum;
use Testo\Assert;
use Testo\Test;

/**
 * Conditions as per-row tests built once: every LIKE shape and every
 * other operator answer exactly what the reference evaluation answers —
 * a LIKE pattern rebuilt into an anchored regular expression for each
 * value, and the operator applied to the raw field value.
 */
final class LikePatternTest
{
    /**
     * Random patterns over wildcards, escapes, regex metacharacters, a
     * newline and a multibyte letter match random values exactly as the
     * reference regular expression does, with and without NOT, through
     * the condition and through the operator.
     */
    #[Test]
    public function likeMatchesReference(): void
    {
        mt_srand(20261005);
        $alphabet = ['a', 'b', '%', '%', '\\', '_', '.', '*', '/', "\n", 'é'];
        $values = [null, 7, 1.5, true, ''];

        for ($i = 0; $i < 300; $i++) {
            $values[] = self::randomString(
                ['a', 'b', '%', '\\', '_', '.', "\n", 'é'],
                6,
            );
        }

        for ($p = 0; $p < 400; $p++) {
            $pattern = self::randomString($alphabet, 5);

            foreach ([false, true] as $not) {
                $condition = new FilterCondition(
                    'f',
                    FilterOperatorEnum::LIKE,
                    $pattern,
                    $not,
                );
                $test = $condition->predicate();

                foreach ($values as $value) {
                    $expected = self::referenceLike($value, $pattern) !== $not;
                    $label = json_encode([$pattern, $value, $not]);

                    Assert::same(
                        $test(['f' => $value]),
                        $expected,
                        (string)$label,
                    );
                    Assert::same(
                        $condition->matches(['f' => $value]),
                        $expected,
                        (string)$label,
                    );

                    if (!$not) {
                        Assert::same(
                            FilterOperatorEnum::LIKE->matches($value, $pattern),
                            $expected,
                            (string)$label,
                        );
                    }
                }
            }
        }
    }

    /**
     * A non-string pattern never matches, and NOT of it always does.
     */
    #[Test]
    public function nonStringPatternNeverMatches(): void
    {
        foreach ([null, 5, ['a']] as $pattern) {
            $like = new FilterCondition(
                'f',
                FilterOperatorEnum::LIKE,
                $pattern,
            );
            $notLike = new FilterCondition(
                'f',
                FilterOperatorEnum::LIKE,
                $pattern,
                true,
            );

            Assert::false($like->matches(['f' => 'a']));
            Assert::true($notLike->matches(['f' => 'a']));
        }
    }

    /**
     * Every other operator, with and without NOT and in both comparison
     * modes, answers what the operator answers for the field value — a
     * missing field reading as null.
     */
    #[Test]
    public function otherOperatorsMatchOperator(): void
    {
        $values = [null, 0, 1, 2, 1.5, -0.0, 0.0, '1', 'a', 'b', true, false];
        $arguments = [
            '='       => [null, 1, 1.0, '1', 'a', true, 0.0],
            '>'       => [1, 1.5, 'a', null],
            '>='      => [1, 'b'],
            '<'       => [2, 'b', null],
            '<='      => [1.5, 'a'],
            'BETWEEN' => [[0, 1.5], ['a', 'b'], 3, [1]],
            'IN'      => [[1, 'a', null], [], 'x'],
        ];

        $modes = [ComparisonModeEnum::Binary, ComparisonModeEnum::Locale];

        foreach ($modes as $mode) {
            foreach (FilterOperatorEnum::cases() as $operator) {
                foreach ($arguments[$operator->value] ?? [] as $argument) {
                    foreach ([false, true] as $not) {
                        $condition = new FilterCondition(
                            'f',
                            $operator,
                            $argument,
                            $not,
                        );
                        $test = $condition->predicate($mode);

                        Assert::same(
                            $test([]),
                            $operator->matches(null, $argument, $mode) !== $not,
                        );

                        foreach ($values as $value) {
                            Assert::same(
                                $test(['f' => $value]),
                                $operator->matches($value, $argument, $mode)
                                    !== $not,
                                json_encode([
                                    $operator->value,
                                    $argument,
                                    $value,
                                    $not,
                                ]) . ' ' . $mode->name,
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * The LIKE evaluation the engine had before patterns were parsed once:
     * an anchored regular expression rebuilt for every value.
     */
    private static function referenceLike(mixed $value, string $pattern): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        $regex = '';
        $len = \strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $len) {
                $regex .= preg_quote($pattern[$i + 1], '/');
                $i++;

                continue;
            }

            if ($char === '%') {
                if (!str_ends_with($regex, '.*')) {
                    $regex .= '.*';
                }

                continue;
            }

            $regex .= preg_quote($char, '/');
        }

        return preg_match('/^' . $regex . '$/sD', $value) === 1;
    }

    /**
     * @param array<int,string> $alphabet
     */
    private static function randomString(
        array $alphabet,
        int $maxLength,
    ): string {
        $string = '';
        $length = mt_rand(0, $maxLength);

        for ($i = 0; $i < $length; $i++) {
            $string .= $alphabet[mt_rand(0, \count($alphabet) - 1)];
        }

        return $string;
    }
}
