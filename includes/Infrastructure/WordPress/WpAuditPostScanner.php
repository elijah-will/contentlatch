<?php
/**
 * ID-only post scanner for audits.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- ID/cursor audit scans have no Core API equivalent that preserves this query.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor pagination must read live wp_posts rows, not object cache.

use ContentLatch\Application\Audit\AuditPost;
use ContentLatch\Application\Audit\AuditPostScanner;

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

        $typePlaceholders   = $this->stringPlaceholders(count($postTypes));
        $statusPlaceholders = $this->stringPlaceholders(count($statuses));
        if ($typePlaceholders === '' || $statusPlaceholders === '') {
            return array();
        }

        if ($count) {
            $sql  = 'SELECT COUNT(ID) AS total FROM %i WHERE post_type IN (' . $typePlaceholders . ') AND post_status IN (' . $statusPlaceholders . ')';
            $args = array_merge(array($wpdb->posts), $postTypes, $statuses);
        } else {
            $sql  = 'SELECT ID, post_type FROM %i WHERE post_type IN (' . $typePlaceholders . ') AND post_status IN (' . $statusPlaceholders . ') AND ID > %d ORDER BY ID ASC LIMIT %d';
            $args = array_merge(array($wpdb->posts), $postTypes, $statuses, array($cursor, $limit));
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $wpdb->prepare() has no array placeholder. stringPlaceholders() contributes only %s tokens; post types, statuses, cursor, and limit are prepare arguments.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Comma-separated %s tokens for a variable IN list. Never contains values.
     */
    private function stringPlaceholders(int $count): string
    {
        if ($count < 1) {
            return '';
        }

        $placeholders = implode(',', array_fill(0, $count, '%s'));

        return preg_match('/^%s(?:,%s)*$/', $placeholders) === 1 ? $placeholders : '';
    }
}
