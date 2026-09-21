<?php
/**
 * ID-only post scanner for audits.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

defined('ABSPATH') || exit;

use ContentGuard\Application\Audit\AuditPost;
use ContentGuard\Application\Audit\AuditPostScanner;

final class WpAuditPostScanner implements AuditPostScanner
{
    /**
     * @param string[] $postTypes
     * @param string[] $statuses
     * @return AuditPost[]
     */
    public function scan(array $postTypes, array $statuses, int $cursor, int $limit): array
    {
        $rows = $this->query($postTypes, $statuses, $cursor, $limit, false);
        $posts = array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['ID'] ?? 0);
            $type = (string) ($row['post_type'] ?? '');
            if ($id > 0 && $type !== '') {
                $posts[] = new AuditPost($id, $type);
            }
        }

        return $posts;
    }

    /**
     * @param string[] $postTypes
     * @param string[] $statuses
     */
    public function count(array $postTypes, array $statuses): int
    {
        $rows = $this->query($postTypes, $statuses, 0, 0, true);

        return isset($rows[0]['total']) ? (int) $rows[0]['total'] : 0;
    }

    /**
     * @param string[] $postTypes
     * @param string[] $statuses
     * @return array<int, array<string, mixed>>
     */
    private function query(array $postTypes, array $statuses, int $cursor, int $limit, bool $count): array
    {
        global $wpdb;

        $postTypes = array_values(array_filter($postTypes, static fn (string $type): bool => $type !== ''));
        $statuses  = array_values(array_filter($statuses, static fn (string $status): bool => $status !== ''));

        if ($postTypes === array() || $statuses === array()) {
            return array();
        }

        $typePlaceholders   = implode(',', array_fill(0, count($postTypes), '%s'));
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        if ($count) {
            $sql = "SELECT COUNT(ID) AS total FROM {$wpdb->posts} WHERE post_type IN ({$typePlaceholders}) AND post_status IN ({$statusPlaceholders})";
            $args = array_merge($postTypes, $statuses);
        } else {
            $sql = "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_type IN ({$typePlaceholders}) AND post_status IN ({$statusPlaceholders}) AND ID > %d ORDER BY ID ASC LIMIT %d";
            $args = array_merge($postTypes, $statuses, array($cursor, $limit));
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }
}
