<?php
/**
 * ContentLatch uninstall.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$contentlatch_autoload = __DIR__ . '/vendor/autoload.php';

if (is_readable($contentlatch_autoload)) {
    require_once $contentlatch_autoload;
} else {
    require_once __DIR__ . '/includes/Autoloader.php';
    if (!defined('CONTENTLATCH_DIR')) {
        define('CONTENTLATCH_DIR', __DIR__ . '/');
    }
    ContentLatch\Autoloader::register();
}

ContentLatch\Uninstaller::run();
