<?php
/**
 * ContentGuard uninstall.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$contentguard_autoload = __DIR__ . '/vendor/autoload.php';

if (is_readable($contentguard_autoload)) {
    require_once $contentguard_autoload;
} else {
    require_once __DIR__ . '/includes/Autoloader.php';
    if (!defined('CONTENTGUARD_DIR')) {
        define('CONTENTGUARD_DIR', __DIR__ . '/');
    }
    ContentGuard\Autoloader::register();
}

ContentGuard\Uninstaller::run();
