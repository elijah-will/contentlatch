<?php
/**
 * Soft gettext for Domain user-facing messages.
 *
 * Keeps the Domain layer free of a hard WordPress dependency while still
 * registering strings for the contentguard text domain.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

final class Text
{
    public static function translate(string $text): string
    {
        return function_exists('__') ? __($text, 'contentguard') : $text;
    }

    /**
     * @param mixed ...$args
     */
    public static function sprintf(string $format, mixed ...$args): string
    {
        return sprintf(self::translate($format), ...$args);
    }
}
