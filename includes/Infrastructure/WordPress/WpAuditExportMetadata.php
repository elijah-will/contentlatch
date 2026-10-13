<?php
/**
 * Current WordPress metadata for the CSV exporter, without retained post caches.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

use ContentLatch\Application\Audit\AuditExportMetadata;
use ContentLatch\Application\Exception\AuditException;
use ContentLatch\Application\RuleRepositoryInterface;
use ContentLatch\Domain\Rule;

final class WpAuditExportMetadata implements AuditExportMetadata
{
    public function __construct(private RuleRepositoryInterface $rules)
    {
    }

    public function post(int $postId): ?array
    {
        if ($postId <= 0) {
            return null;
        }

        return $this->withoutPostCache($postId, static function () use ($postId): ?array {
            $post = get_post($postId);
            if ($post === null) {
                return null;
            }

            // Core may load the full post to apply edit_post capability mapping.
            // Keep that behavior (including permission/edit-link filters), but
            // return only these three strings and release the cache afterwards.
            $editUrl = get_edit_post_link($postId, 'raw');

            return array(
                'title'    => (string) $post->post_title,
                'status'   => (string) $post->post_status,
                'edit_url' => is_string($editUrl) ? $editUrl : '',
            );
        });
    }

    public function rule(int|string $ruleId): ?Rule
    {
        // WordPress rule IDs are positive post IDs; never let get_post(0)
        // accidentally resolve the global post for a missing/legacy rule ID.
        if (!ctype_digit((string) $ruleId) || (int) $ruleId <= 0) {
            return null;
        }

        return $this->withoutPostCache((int) $ruleId, fn (): ?Rule => $this->rules->find($ruleId));
    }

    /**
     * The exporter already deduplicates lookups within its bounded batch.
     * Evict only the touched post/meta keys, including cache hits: persistent
     * cache backends can retain hits in process memory. Allow Core to reuse
     * these keys during a lookup so permission checks do not reread the post.
     * Do not flush the site-wide or whole request cache. Core/plugin permission
     * checks can still read a post body; we do not retain or export that body.
     *
     * @template T
     * @param callable(): T $read
     * @return T
     */
    private function withoutPostCache(int $postId, callable $read): mixed
    {
        try {
            $this->assertDatabaseReady();
            $value = $read();
            $this->assertDatabaseReady();

            return $value;
        } finally {
            wp_cache_delete($postId, 'posts');
            wp_cache_delete($postId, 'post_meta');
        }
    }

    private function assertDatabaseReady(): void
    {
        global $wpdb;

        if (!$wpdb->ready || $wpdb->last_error !== '') {
            throw new AuditException('Could not load audit export metadata.');
        }
    }
}
