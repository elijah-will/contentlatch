<?php
/**
 * PSR-4 autoloader used when Composer is not installed.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch;

defined('ABSPATH') || exit;

final class Autoloader
{
    private const PREFIX = 'ContentLatch\\';

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
        $file     = CONTENTLATCH_DIR . 'includes/' . $relative . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
}
