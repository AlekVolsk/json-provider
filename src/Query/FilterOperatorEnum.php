<?php

declare(strict_types=1);

namespace AV\JsonProvider\Query;

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
     */
    public function matches(
        bool | float | int | string | null $recordValue,
        mixed $conditionValue,
    ): bool {
        return match ($this) {
            self::EQ => $recordValue === $conditionValue,
            self::GT => $recordValue !== null
                && \is_scalar($conditionValue)
                && $recordValue > $conditionValue,
            self::GTE => $recordValue !== null
                && \is_scalar($conditionValue)
                && $recordValue >= $conditionValue,
            self::LT => $recordValue !== null
                && \is_scalar($conditionValue)
                && $recordValue < $conditionValue,
            self::LTE => $recordValue !== null
                && \is_scalar($conditionValue)
                && $recordValue <= $conditionValue,
            self::LIKE    => $this->matchesLike($recordValue, $conditionValue),
            self::BETWEEN => $this->matchesBetween(
                $recordValue,
                $conditionValue,
            ),
            self::IN => $this->matchesIn($recordValue, $conditionValue),
        };
    }

    /**
     * LIKE: supports % as wildcard, case-sensitive.
     */
    private function matchesLike(
        bool | float | int | string | null $recordValue,
        mixed $conditionValue,
    ): bool {
        if (!\is_string($recordValue) || !\is_string($conditionValue)) {
            return false;
        }

        $pattern = '/^'
            . str_replace('%', '.*', preg_quote($conditionValue, '/'))
            . '$/s';

        return (bool)preg_match($pattern, $recordValue);
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
    ): bool {
        if ($recordValue === null || !\is_array($conditionValue)) {
            return false;
        }

        [$min, $max] = $conditionValue;

        return $recordValue >= $min && $recordValue <= $max;
    }
}
