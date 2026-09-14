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

    /**
     * Numeric comparison form. Empty and invalid values are null, never 0.
     * Integer 0 and string "0" remain 0.0.
     */
    public static function tryNumber(mixed $value): ?float
    {
        if ($value === null || $value === false || $value === '' || $value === array()) {
            return null;
        }

        if (is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return $value;
        }

        if (!is_string($value) || !is_numeric($value)) {
            return null;
        }

        if ($value !== trim($value) || str_contains($value, ',')) {
            return null;
        }

        return (float) $value;
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
     * Case-insensitive literal substring match.
     * Empty values and empty search text never match.
     */
    public static function contains(mixed $value, mixed $needle): bool
    {
        if (self::isEmpty($value)) {
            return false;
        }

        $haystack = self::toComparableString($value);
        $search   = self::toComparableString($needle);

        if ($haystack === null || $search === null || $search === '') {
            return false;
        }

        return mb_stripos($haystack, $search, 0, 'UTF-8') !== false;
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
