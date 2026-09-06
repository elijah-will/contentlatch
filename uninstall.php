<?php
/**
 * ContentGuard uninstall.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$autoload = __DIR__ . '/vendor/autoload.php';

if (is_readable($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/includes/Autoloader.php';
    if (!defined('CONTENTGUARD_DIR')) {
        define('CONTENTGUARD_DIR', __DIR__ . '/');
    }
    ContentGuard\Autoloader::register();
}

ContentGuard\Uninstaller::run();
