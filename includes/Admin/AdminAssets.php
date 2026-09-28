<?php
/**
 * Shared ContentLatch admin assets.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Admin;

defined('ABSPATH') || exit;

final class AdminAssets
{
    public const STYLE = 'contentlatch-admin';

    public static function enqueueShared(): void
    {
        wp_register_style(
            self::STYLE,
            CONTENTLATCH_URL . 'admin/css/contentlatch.css',
            array(),
            \ContentLatch\Plugin::VERSION
        );
        wp_enqueue_style(self::STYLE);
    }
}
