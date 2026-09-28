<?php
/**
 * Human-readable field values for admin presentation.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application;

defined('ABSPATH') || exit;

final class FieldValuePresentation
{
    public static function isTrueFalse(?string $fieldType): bool
    {
        return $fieldType === 'true_false';
    }

    public static function label(mixed $value, ?string $fieldType): string
    {
        if (self::isTrueFalse($fieldType) && self::isTrueFalseBit($value)) {
            return self::isYes($value) ? __('Yes', 'contentlatch') : __('No', 'contentlatch');
        }

        return self::scalar($value);
    }

    public static function isYes(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    public static function isTrueFalseBit(mixed $value): bool
    {
        return $value === true
            || $value === false
            || $value === 1
            || $value === 0
            || $value === '1'
            || $value === '0';
    }

    public static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
