<?php
/**
 * Soft WordPress i18n for Application presentation.
 *
 * Returns the English source string when WordPress gettext is unavailable
 * (unit tests and Domainless callers).
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

defined('ABSPATH') || exit;

final class I18n
{
    public static function translate(string $text): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Wrapper passes caller literals through to __() for Domain/Application soft i18n.
        return function_exists('__') ? __($text, 'contentguard') : $text;
    }

    public static function translateContext(string $text, string $context): string
    {
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralContext -- Wrapper passes caller literals through to _x().
        return function_exists('_x') ? _x($text, $context, 'contentguard') : $text;
    }

    public static function translatePlural(string $single, string $plural, int $number): string
    {
        if (function_exists('_n')) {
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralSingle, WordPress.WP.I18n.NonSingularStringLiteralPlural -- Wrapper passes caller literals through to _n().
            return _n($single, $plural, $number, 'contentguard');
        }

        return $number === 1 ? $single : $plural;
    }

    /**
     * @param mixed ...$args
     */
    public static function sprintf(string $format, mixed ...$args): string
    {
        return sprintf($format, ...$args);
    }
}
