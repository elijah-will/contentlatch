<?php
/**
 * Translates stable English Domain messages at the presentation boundary.
 *
 * Domain code returns these strings without calling WordPress gettext.
 * Each arm below is a literal gettext call so translation tools can
 * discover the source text.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

defined('ABSPATH') || exit;

final class DomainMessages
{
    public static function present(string $message): string
    {
        $exact = self::exact($message);
        if ($exact !== null) {
            return $exact;
        }

        if (preg_match('/^This field must be at least (\d+) characters\.$/', $message, $matches) === 1) {
            return sprintf(
                /* translators: %d: minimum number of characters. */
                __('This field must be at least %d characters.', 'contentguard'),
                (int) $matches[1]
            );
        }

        if (preg_match('/^This field must be at most (\d+) characters\.$/', $message, $matches) === 1) {
            return sprintf(
                /* translators: %d: maximum number of characters. */
                __('This field must be at most %d characters.', 'contentguard'),
                (int) $matches[1]
            );
        }

        if (preg_match('/^Add at least one (.+) row\.$/', $message, $matches) === 1) {
            return sprintf(
                /* translators: %s: repeater or layout label. */
                __('Add at least one %s row.', 'contentguard'),
                $matches[1]
            );
        }

        return $message;
    }

    private static function exact(string $message): ?string
    {
        return match ($message) {
            'This field is required.' => __('This field is required.', 'contentguard'),
            'Allowed values are not configured.' => __('Allowed values are not configured.', 'contentguard'),
            'This field must be one of the allowed values.' => __('This field must be one of the allowed values.', 'contentguard'),
            'This field cannot be compared to allowed values.' => __('This field cannot be compared to allowed values.', 'contentguard'),
            'Maximum length is not configured.' => __('Maximum length is not configured.', 'contentguard'),
            'Minimum length is not configured.' => __('Minimum length is not configured.', 'contentguard'),
            'This field cannot be measured as text.' => __('This field cannot be measured as text.', 'contentguard'),
            'Rule skipped because its conditions were not met.' => __('Rule skipped because its conditions were not met.', 'contentguard'),
            'Rule applied with no validations.' => __('Rule applied with no validations.', 'contentguard'),
            'This content matches the rule condition.' => __('This content matches the rule condition.', 'contentguard'),
            default => null,
        };
    }
}
