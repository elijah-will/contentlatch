<?php
/**
 * Deactivation hook.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard;

final class Deactivator
{
    public static function deactivate(): void
    {
        // Data is retained on deactivation. Uninstall removes stored plugin data.
    }
}
