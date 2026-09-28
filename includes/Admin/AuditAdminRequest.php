<?php
/**
 * Keeps Audit filter query args from colliding with WordPress admin routing.
 *
 * WordPress admin.php treats `post_type` as reserved: if it names a real CPT,
 * `$typenow` is set and the plugin page is looked up as
 * `admin.php?post_type={type}` instead of `admin.php`. That makes
 * `get_plugin_page_hook('contentlatch-audit', ...)` miss and WordPress dies
 * with "Cannot load contentlatch-audit."
 *
 * This remapper must run on `plugins_loaded`, before admin.php reads
 * `$_REQUEST['post_type']`.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Admin;

defined('ABSPATH') || exit;

final class AuditAdminRequest
{
    public const TYPE_QUERY_ARG = 'cl_type';

    public static function register(): void
    {
        // Plugin boot already runs on plugins_loaded (priority 10). A nested
        // priority-0 callback would miss this request. Remap immediately so
        // admin.php never sees `post_type` when it sets $typenow.
        self::remapReservedQueryArgs();
    }

    public static function remapReservedQueryArgs(): void
    {
        if (!function_exists('is_admin') || !is_admin()) {
            return;
        }

        self::apply($_GET, $_REQUEST, $_POST);
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $request
     * @param array<string, mixed> $post
     */
    public static function apply(array &$get, array &$request, array &$post): bool
    {
        $page = self::scalar($get['page'] ?? $request['page'] ?? '');
        if (function_exists('wp_unslash')) {
            $page = (string) wp_unslash($page);
        }
        $page = function_exists('sanitize_key') ? sanitize_key($page) : $page;
        if ($page !== AuditPage::SLUG) {
            return false;
        }

        $legacy = self::scalar($get['post_type'] ?? $request['post_type'] ?? '');
        if ($legacy === '') {
            return false;
        }

        if (function_exists('wp_unslash')) {
            $legacy = (string) wp_unslash($legacy);
        }
        $safe = function_exists('sanitize_key') ? sanitize_key($legacy) : $legacy;
        $existing = self::scalar($get[self::TYPE_QUERY_ARG] ?? $request[self::TYPE_QUERY_ARG] ?? '');
        if ($existing === '' && $safe !== '') {
            $get[self::TYPE_QUERY_ARG]     = $safe;
            $request[self::TYPE_QUERY_ARG] = $safe;
        }

        unset($get['post_type'], $request['post_type'], $post['post_type']);

        return true;
    }

    /**
     * WordPress dies with "Cannot load contentlatch-audit." when these stay
     * on the Audit admin request for a registered post type.
     *
     * @param array<string, mixed> $request
     */
    public static function reservedPostTypeWouldBreakAuditPage(array $request): bool
    {
        $page = self::scalar($request['page'] ?? '');
        $type = self::scalar($request['post_type'] ?? '');

        return $page === AuditPage::SLUG && $type !== '';
    }

    private static function scalar(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
