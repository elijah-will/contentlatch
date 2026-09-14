<?php
/**
 * Human-readable field values for admin presentation.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

final class FieldValuePresentation
{
    public static function isTrueFalse(?string $fieldType): bool
    {
        return $fieldType === 'true_false';
    }

    public static function label(mixed $value, ?string $fieldType): string
    {
        if (self::isTrueFalse($fieldType) && self::isTrueFalseBit($value)) {
            return self::isYes($value) ? I18n::translate('Yes') : I18n::translate('No');
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
