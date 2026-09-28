<?php
/**
 * Removes plugin data on uninstall.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch;

defined('ABSPATH') || exit;

use ContentLatch\Admin\RuleEditorDraftStore;
use ContentLatch\Infrastructure\WordPress\AuditSchema;
use ContentLatch\Infrastructure\WordPress\Capabilities;
use ContentLatch\Infrastructure\WordPress\RulePostType;

final class Uninstaller
{
    public static function run(): void
    {
        if (!function_exists('get_posts') || !function_exists('wp_delete_post')) {
            return;
        }

        $posts = get_posts(
            array(
                'post_type'      => RulePostType::POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            )
        );

        foreach ($posts as $postId) {
            wp_delete_post((int) $postId, true);
        }

        Capabilities::revoke();
        AuditSchema::drop();
        RuleEditorDraftStore::deleteAllStored();
    }
}
