<?php
/**
 * Domain value helpers for emptiness, equality, and length.
 *
 * Empty means: null, false, '', or [].
 * Integer 0 and string "0" are not empty.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

final class Value
{
    public static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return true;
        }

        return is_array($value) && $value === array();
    }

    /**
     * Scalar string form used for equals/allowed-values comparison.
     * Returns null for non-scalars (arrays/objects).
     */
    public static function toComparableString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return null;
    }

    public static function equals(mixed $left, mixed $right): bool
    {
        $leftEmpty  = self::isEmpty($left);
        $rightEmpty = self::isEmpty($right);

        if ($leftEmpty || $rightEmpty) {
            return $leftEmpty && $rightEmpty;
        }

        $leftString  = self::toComparableString($left);
        $rightString = self::toComparableString($right);

        if ($leftString === null || $rightString === null) {
            return false;
        }

        return $leftString === $rightString;
    }

    /**
     * Character length for string-like values.
     * Empty values have length 0. Non-scalars return null.
     */
    public static function stringLength(mixed $value): ?int
    {
        if (self::isEmpty($value)) {
            return 0;
        }

        $string = self::toComparableString($value);

        if ($string === null) {
            return null;
        }

        return mb_strlen($string);
    }
}
