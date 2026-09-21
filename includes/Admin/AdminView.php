<?php
/**
 * Admin view partial loader.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

defined('ABSPATH') || exit;

final class AdminView
{
    /**
     * @param array<string, mixed> $vars
     */
    public static function partial(string $name, array $vars = array()): void
    {
        $file = CONTENTGUARD_DIR . 'admin/views/partials/' . $name . '.php';
        if (!is_readable($file)) {
            return;
        }

        extract($vars, EXTR_SKIP);
        require $file;
    }
}
