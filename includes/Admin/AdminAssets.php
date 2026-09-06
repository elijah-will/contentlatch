<?php
/**
 * Shared ContentGuard admin assets.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

final class AdminAssets
{
    public const STYLE = 'contentguard-admin';

    public static function enqueueShared(): void
    {
        wp_register_style(
            self::STYLE,
            CONTENTGUARD_URL . 'admin/css/contentguard.css',
            array(),
            \ContentGuard\Plugin::VERSION
        );
        wp_enqueue_style(self::STYLE);
    }
}
