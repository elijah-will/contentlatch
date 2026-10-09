<?php
/**
 * Plugin Name:       ContentLatch
 * Description:       Define content rules for WordPress Core and ACF fields, then validate before publication.
 * Version:           1.0.1
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            ContentLatch
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contentlatch
 * Domain Path:       /languages
 *
 * @package ContentLatch
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('CONTENTLATCH_FILE', __FILE__);
define('CONTENTLATCH_DIR', plugin_dir_path(__FILE__));
define('CONTENTLATCH_URL', plugin_dir_url(__FILE__));

$contentlatch_autoload = CONTENTLATCH_DIR . 'vendor/autoload.php';

if (is_readable($contentlatch_autoload)) {
    require_once $contentlatch_autoload;
} else {
    require_once CONTENTLATCH_DIR . 'includes/Autoloader.php';
    ContentLatch\Autoloader::register();
}

register_activation_hook(CONTENTLATCH_FILE, static function (): void {
    ContentLatch\Activator::activate();
});

register_deactivation_hook(CONTENTLATCH_FILE, static function (): void {
    ContentLatch\Deactivator::deactivate();
});

add_action(
    'plugins_loaded',
    static function (): void {
        ContentLatch\Plugin::instance()->boot();
    }
);
