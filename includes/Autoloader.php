<?php
/**
 * PSR-4 autoloader used when Composer is not installed.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard;

final class Autoloader
{
    private const PREFIX = 'ContentGuard\\';

    public static function register(): void
    {
        spl_autoload_register(array(self::class, 'load'));
    }

    public static function load(string $class): void
    {
        if (!str_starts_with($class, self::PREFIX)) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen(self::PREFIX)));
        $file     = CONTENTGUARD_DIR . 'includes/' . $relative . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
}
