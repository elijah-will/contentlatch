<?php
/**
 * In-memory ID scanner for audit tests.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Support;

use ContentGuard\Application\Audit\AuditPost;
use ContentGuard\Application\Audit\AuditPostScanner;

final class InMemoryAuditPostScanner implements AuditPostScanner
{
    /**
     * @param array<int, array{id: int, postType: string, status: string}> $posts
     */
    public function __construct(private array $posts = array())
    {
    }

    public function scan(array $postTypes, array $statuses, int $cursor, int $limit): array
    {
        $ordered = $this->posts;
        usort(
            $ordered,
            static fn (array $left, array $right): int => $left['id'] <=> $right['id']
        );

        $hits = array();

        foreach ($ordered as $post) {
            if ($post['id'] <= $cursor) {
                continue;
            }

            if (!in_array($post['postType'], $postTypes, true)) {
                continue;
            }

            if (!in_array($post['status'], $statuses, true)) {
                continue;
            }

            $hits[] = new AuditPost($post['id'], $post['postType']);
            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }

    public function count(array $postTypes, array $statuses): int
    {
        return count($this->scan($postTypes, $statuses, 0, PHP_INT_MAX));
    }
}
