<?php

declare(strict_types=1);

namespace AV\JsonProvider\Query;

use AV\JsonProvider\Exception\JsonProviderQueryException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

/**
 * Comparison operators for record filtering.
 * The NOT variant is expressed via the flag in FilterCondition, not a
 * separate enum case.
 */
enum FilterOperatorEnum: string
{
    case EQ = '=';
    case GT = '>';
    case GTE = '>=';
    case LT = '<';
    case LTE = '<=';
    case LIKE = 'LIKE';
    case BETWEEN = 'BETWEEN';
    case IN = 'IN';

    /**
     * Applies the operator to a record value against the condition value.
     * Ordering operators delegate to ValueComparator, so ranges agree with
     * ORDER BY and (in Binary mode) with the byte-encoded index order;
     * equality stays strict `===`.
     */
    public function matches(
        bool | float | int | string | null $recordValue,
        mixed $conditionValue,
        ComparisonMode $mode = ComparisonMode::Binary,
    ): bool {
        return match ($this) {
            self::EQ => $recordValue === $conditionValue,
            self::GT => $recordValue !== null
                && \is_scalar($conditionValue)
                && ValueComparator::compare(
                    $recordValue,
                    $conditionValue,
                    $mode,
                ) > 0,
            self::GTE => $recordValue !== null
                && \is_scalar($conditionValue)
                && ValueComparator::compare(
                    $recordValue,
                    $conditionValue,
                    $mode,
                ) >= 0,
            self::LT => $recordValue !== null
                && \is_scalar($conditionValue)
                && ValueComparator::compare(
                    $recordValue,
                    $conditionValue,
                    $mode,
                ) < 0,
            self::LTE => $recordValue !== null
                && \is_scalar($conditionValue)
                && ValueComparator::compare(
                    $recordValue,
                    $conditionValue,
                    $mode,
                ) <= 0,
            self::LIKE    => $this->matchesLike($recordValue, $conditionValue),
            self::BETWEEN => $this->matchesBetween(
                $recordValue,
                $conditionValue,
                $mode,
            ),
            self::IN => $this->matchesIn($recordValue, $conditionValue),
        };
    }

    /**
     * LIKE: bytewise and case-sensitive. An unescaped `%` is the only
     * wildcard (matches any byte run); a backslash escapes the next
     * character, so `\%` matches a literal percent and `\\` a literal
     * backslash. `_` is NOT a wildcard — it matches a literal underscore.
     */
    private function matchesLike(
        bool | float | int | string | null $recordValue,
        mixed $conditionValue,
    ): bool {
        if (!\is_string($recordValue) || !\is_string($conditionValue)) {
            return false;
        }

        $regex = '';
        $len = \strlen($conditionValue);

        for ($i = 0; $i < $len; $i++) {
            $char = $conditionValue[$i];

            if ($char === '\\' && $i + 1 < $len) {
                $regex .= preg_quote($conditionValue[$i + 1], '/');
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

        $result = preg_match('/^' . $regex . '$/s', $recordValue);

        if ($result === false) {
            throw new JsonProviderQueryException(
                JsonProviderErrorEn::LikeEvaluationFailed,
                $conditionValue,
                preg_last_error_msg(),
            );
        }

        return $result === 1;
    }

    /**
     * IN: conditionValue = array of scalars, strict comparison.
     */
    private function matchesIn(
        bool | float | int | string | null $recordValue,
        mixed $conditionValue,
    ): bool {
        if (!\is_array($conditionValue)) {
            return false;
        }

        return \in_array($recordValue, $conditionValue, true);
    }

    /**
     * BETWEEN: conditionValue = [min, max], inclusive on both ends.
     */
    private function matchesBetween(
        bool | float | int | string | null $recordValue,
        mixed $conditionValue,
        ComparisonMode $mode,
    ): bool {
        if (
            $recordValue === null
            || !\is_array($conditionValue)
            || !\array_key_exists(0, $conditionValue)
            || !\array_key_exists(1, $conditionValue)
        ) {
            return false;
        }

        $min = $conditionValue[0];
        $max = $conditionValue[1];

        if (
            (!\is_scalar($min) && $min !== null)
            || (!\is_scalar($max) && $max !== null)
        ) {
            return false;
        }

        return ValueComparator::compare($recordValue, $min, $mode) >= 0
            && ValueComparator::compare($recordValue, $max, $mode) <= 0;
    }
}
