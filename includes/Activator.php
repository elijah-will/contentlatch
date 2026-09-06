<?php
/**
 * Activation hook.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard;

use ContentGuard\Infrastructure\WordPress\AuditSchema;
use ContentGuard\Infrastructure\WordPress\Capabilities;
use ContentGuard\Infrastructure\WordPress\RulePostType;

final class Activator
{
    public static function activate(): void
    {
        if (version_compare(PHP_VERSION, Plugin::MIN_PHP, '<')) {
            deactivate_plugins(plugin_basename(CONTENTGUARD_FILE));
            wp_die(
                esc_html(
                    sprintf(
                        /* translators: %s: minimum PHP version */
                        __('ContentGuard requires PHP %s or higher.', 'contentguard'),
                        Plugin::MIN_PHP
                    )
                )
            );
        }

        global $wp_version;

        if (isset($wp_version) && version_compare($wp_version, Plugin::MIN_WP, '<')) {
            deactivate_plugins(plugin_basename(CONTENTGUARD_FILE));
            wp_die(
                esc_html(
                    sprintf(
                        /* translators: %s: minimum WordPress version */
                        __('ContentGuard requires WordPress %s or higher.', 'contentguard'),
                        Plugin::MIN_WP
                    )
                )
            );
        }

        Capabilities::grant();
        RulePostType::register();
        AuditSchema::install();
    }
}
