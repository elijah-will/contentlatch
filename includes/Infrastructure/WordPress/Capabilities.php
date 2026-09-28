<?php
/**
 * ContentLatch capabilities.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

final class Capabilities
{
    public const MANAGE = 'manage_contentlatch';

    public static function grant(): void
    {
        if (!function_exists('get_role')) {
            return;
        }

        $role = get_role('administrator');
        if ($role === null) {
            return;
        }

        if (!$role->has_cap(self::MANAGE)) {
            $role->add_cap(self::MANAGE);
        }
    }

    public static function revoke(): void
    {
        if (!function_exists('get_role')) {
            return;
        }

        $role = get_role('administrator');
        if ($role === null) {
            return;
        }

        if ($role->has_cap(self::MANAGE)) {
            $role->remove_cap(self::MANAGE);
        }
    }

    public static function currentUserCanManage(): bool
    {
        return function_exists('current_user_can') && current_user_can(self::MANAGE);
    }
}
