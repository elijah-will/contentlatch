<?php
/**
 * Plugin Name:       ContentGuard
 * Description:       Define content rules for WordPress Core and ACF fields, then validate before publication.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            ContentGuard
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contentguard
 * Domain Path:       /languages
 *
 * @package ContentGuard
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('CONTENTGUARD_FILE', __FILE__);
define('CONTENTGUARD_DIR', plugin_dir_path(__FILE__));
define('CONTENTGUARD_URL', plugin_dir_url(__FILE__));

$contentguard_autoload = CONTENTGUARD_DIR . 'vendor/autoload.php';

if (is_readable($contentguard_autoload)) {
    require_once $contentguard_autoload;
} else {
    require_once CONTENTGUARD_DIR . 'includes/Autoloader.php';
    ContentGuard\Autoloader::register();
}

register_activation_hook(CONTENTGUARD_FILE, static function (): void {
    ContentGuard\Activator::activate();
});

register_deactivation_hook(CONTENTGUARD_FILE, static function (): void {
    ContentGuard\Deactivator::deactivate();
});

add_action(
    'plugins_loaded',
    static function (): void {
        ContentGuard\Plugin::instance()->boot();
    }
);
