<?php
/**
 * Activation hook.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch;

defined('ABSPATH') || exit;

use ContentLatch\Infrastructure\WordPress\AuditSchema;
use ContentLatch\Infrastructure\WordPress\Capabilities;
use ContentLatch\Infrastructure\WordPress\RulePostType;

final class Activator
{
    public static function activate(): void
    {
        if (version_compare(PHP_VERSION, Plugin::MIN_PHP, '<')) {
            deactivate_plugins(plugin_basename(CONTENTLATCH_FILE));
            wp_die(
                esc_html(
                    sprintf(
                        /* translators: %s: minimum PHP version */
                        __('ContentLatch requires PHP %s or higher.', 'contentlatch'),
                        Plugin::MIN_PHP
                    )
                )
            );
        }

        global $wp_version;

        if (isset($wp_version) && version_compare($wp_version, Plugin::MIN_WP, '<')) {
            deactivate_plugins(plugin_basename(CONTENTLATCH_FILE));
            wp_die(
                esc_html(
                    sprintf(
                        /* translators: %s: minimum WordPress version */
                        __('ContentLatch requires WordPress %s or higher.', 'contentlatch'),
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
