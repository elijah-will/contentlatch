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
        // Intentionally empty in Phase 0. Data is retained on deactivation.
    }
}
