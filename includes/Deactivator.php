<?php
/**
 * Deactivation hook.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch;

defined('ABSPATH') || exit;

final class Deactivator
{
    public static function deactivate(): void
    {
        // Data is retained on deactivation. Uninstall removes stored plugin data.
    }
}
