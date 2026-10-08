<?php
/**
 * ID-only post scanner for audits.
 *
 * SQL shape (prepared; values never concatenated into the query string):
 *
 *   SELECT … FROM %i
 *   WHERE post_type IN ( %s[, %s…] )
 *     AND post_status IN ( %s[, %s…] )
 *     [AND ID > %d ORDER BY ID ASC LIMIT %d]
 *
 * $wpdb->prepare() has no array/IN placeholder. The IN lists therefore use a
 * comma-separated run of literal "%s" tokens sized to the filtered list length.
 * Those tokens are generated from a count only — never from post-type or status
 * strings. Table name, types, statuses, cursor, and limit are prepare arguments
 * (%i / %s / %d). Empty type or status lists skip the query entirely.
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

        $postTypes = $this->normalizeSlugs($postTypes);
        $statuses  = $this->normalizeSlugs($statuses);

        if ($postTypes === array() || $statuses === array()) {
            return array();
        }

        // Placeholder fragments contain only the literal token "%s" (comma-separated).
        // Post-type/status values are never written into these strings.
        $typeIn   = $this->stringPlaceholders(count($postTypes));
        $statusIn = $this->stringPlaceholders(count($statuses));
        if ($typeIn === '' || $statusIn === '') {
            return array();
        }

        if ($count) {
            // COUNT uses the same type/status filters as scan (no cursor/limit).
            $sql = 'SELECT COUNT(ID) AS total FROM %i WHERE post_type IN ('
                . $typeIn
                . ') AND post_status IN ('
                . $statusIn
                . ')';
            $args = array_merge(array($wpdb->posts), $postTypes, $statuses);
        } else {
            // Cursor: deterministic ID ASC pages via ID > %d LIMIT %d.
            $sql = 'SELECT ID, post_type FROM %i WHERE post_type IN ('
                . $typeIn
                . ') AND post_status IN ('
                . $statusIn
                . ') AND ID > %d ORDER BY ID ASC LIMIT %d';
            $args = array_merge(array($wpdb->posts), $postTypes, $statuses, array($cursor, $limit));
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- IN lists need N×%s; $typeIn/$statusIn are count-derived "%s" tokens only (see stringPlaceholders). Values bound via prepare: %i table, %s types/statuses, %d cursor/limit.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /**
     * Sanitize and drop empty slugs before they become prepare arguments.
     *
     * @param string[] $values
     * @return list<string>
     */
    private function normalizeSlugs(array $values): array
    {
        $clean = array();

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $slug = sanitize_key($value);
            if ($slug !== '') {
                $clean[] = $slug;
            }
        }

        return array_values($clean);
    }

    /**
     * Comma-separated prepare placeholders for a variable-length string IN list.
     *
     * Output is exclusively the characters %, s, and , in the form
     * "%s" / "%s,%s" / … — never caller-supplied values or SQL keywords.
     */
    private function stringPlaceholders(int $count): string
    {
        if ($count < 1) {
            return '';
        }

        $placeholders = implode(',', array_fill(0, $count, '%s'));

        // Fail closed if the fragment is ever anything other than %s tokens.
        return preg_match('/^%s(?:,%s)*$/', $placeholders) === 1 ? $placeholders : '';
    }
}
