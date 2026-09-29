<?php
/**
 * $wpdb-backed audit persistence.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom audit tables have no Core API equivalent.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Live audit run/findings state must not be served from object cache.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers are $wpdb->prefix + ContentLatch table constants; values use $wpdb->prepare with %i/%d/%s.

use ContentLatch\Application\Audit\AuditFinding;
use ContentLatch\Application\Audit\AuditFindingQuery;
use ContentLatch\Application\Audit\AuditRepeaterCoordinates;
use ContentLatch\Application\Audit\AuditRuleImpact;
use ContentLatch\Application\Audit\AuditRun;
use ContentLatch\Application\Audit\AuditRunStatus;
use ContentLatch\Application\Audit\AuditStoreInterface;
use ContentLatch\Application\Exception\AuditException;
use ContentLatch\Domain\RuleSeverity;

final class WpAuditStore implements AuditStoreInterface
{
    public function insertRun(
        int $actorUserId,
        array $postTypes,
        int $postsTotal,
        string $startedAt,
    ): AuditRun {
        global $wpdb;

        $inserted = $wpdb->insert(
            AuditSchema::runsTable(),
            array(
                'status'              => AuditRunStatus::Pending->value,
                'started_at'          => $startedAt,
                'heartbeat_at'        => $startedAt,
                'posts_scanned'       => 0,
                'posts_passed'        => 0,
                'posts_warned'        => 0,
                'posts_failed'        => 0,
                'posts_not_evaluated' => 0,
                'posts_total'         => $postsTotal,
                AuditSchema::CURSOR_COLUMN => 0,
                'actor_user_id'       => $actorUserId,
                'post_types'          => wp_json_encode(array_values($postTypes)),
            ),
            array('%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s')
        );

        if ($inserted === false || (int) $wpdb->insert_id <= 0) {
            $this->logDbError('Could not create audit run.');
            throw new AuditException('Could not create audit run.');
        }

        $run = $this->findRun((int) $wpdb->insert_id);
        if ($run === null) {
            throw new AuditException('Could not load audit run.');
        }

        return $run;
    }

    public function saveRun(AuditRun $run): AuditRun
    {
        global $wpdb;

        $table  = AuditSchema::runsTable();
        $status = $run->status->value;
        $types  = wp_json_encode(array_values($run->postTypes));

        // Each UPDATE is a complete literal. finished_at and error_message stay SQL NULL
        // or a %s argument. Placeholders are passed one-for-one so the call is static.
        if ($run->finishedAt === null && $run->errorMessage === null) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, finished_at = NULL, heartbeat_at = %s, posts_scanned = %d, posts_passed = %d, posts_warned = %d, posts_failed = %d, posts_not_evaluated = %d, posts_total = %d, scan_cursor = %d, actor_user_id = %d, post_types = %s, error_message = NULL WHERE id = %d',
                    $table,
                    $status,
                    $run->heartbeatAt,
                    $run->postsScanned,
                    $run->postsPassed,
                    $run->postsWarned,
                    $run->postsFailed,
                    $run->postsNotEvaluated,
                    $run->postsTotal,
                    $run->cursor,
                    $run->actorUserId,
                    $types,
                    $run->id
                )
            );
        } elseif ($run->finishedAt !== null && $run->errorMessage === null) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, finished_at = %s, heartbeat_at = %s, posts_scanned = %d, posts_passed = %d, posts_warned = %d, posts_failed = %d, posts_not_evaluated = %d, posts_total = %d, scan_cursor = %d, actor_user_id = %d, post_types = %s, error_message = NULL WHERE id = %d',
                    $table,
                    $status,
                    $run->finishedAt,
                    $run->heartbeatAt,
                    $run->postsScanned,
                    $run->postsPassed,
                    $run->postsWarned,
                    $run->postsFailed,
                    $run->postsNotEvaluated,
                    $run->postsTotal,
                    $run->cursor,
                    $run->actorUserId,
                    $types,
                    $run->id
                )
            );
        } elseif ($run->finishedAt === null && $run->errorMessage !== null) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, finished_at = NULL, heartbeat_at = %s, posts_scanned = %d, posts_passed = %d, posts_warned = %d, posts_failed = %d, posts_not_evaluated = %d, posts_total = %d, scan_cursor = %d, actor_user_id = %d, post_types = %s, error_message = %s WHERE id = %d',
                    $table,
                    $status,
                    $run->heartbeatAt,
                    $run->postsScanned,
                    $run->postsPassed,
                    $run->postsWarned,
                    $run->postsFailed,
                    $run->postsNotEvaluated,
                    $run->postsTotal,
                    $run->cursor,
                    $run->actorUserId,
                    $types,
                    $run->errorMessage,
                    $run->id
                )
            );
        } else {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, finished_at = %s, heartbeat_at = %s, posts_scanned = %d, posts_passed = %d, posts_warned = %d, posts_failed = %d, posts_not_evaluated = %d, posts_total = %d, scan_cursor = %d, actor_user_id = %d, post_types = %s, error_message = %s WHERE id = %d',
                    $table,
                    $status,
                    $run->finishedAt,
                    $run->heartbeatAt,
                    $run->postsScanned,
                    $run->postsPassed,
                    $run->postsWarned,
                    $run->postsFailed,
                    $run->postsNotEvaluated,
                    $run->postsTotal,
                    $run->cursor,
                    $run->actorUserId,
                    $types,
                    $run->errorMessage,
                    $run->id
                )
            );
        }

        if ($updated === false) {
            $this->logDbError('Could not save audit run.');
            throw new AuditException('Could not save audit run.');
        }

        return $run;
    }

    public function findRun(int $id): ?AuditRun
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d',
                AuditSchema::runsTable(),
                $id
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrateRun($row) : null;
    }

    public function findActiveRun(): ?AuditRun
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE status IN (%s, %s) ORDER BY id ASC LIMIT 1',
                AuditSchema::runsTable(),
                AuditRunStatus::Pending->value,
                AuditRunStatus::Running->value
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrateRun($row) : null;
    }

    public function findLatestRun(): ?AuditRun
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i ORDER BY id DESC LIMIT 1',
                AuditSchema::runsTable()
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrateRun($row) : null;
    }

    public function findLatestCompleteRun(): ?AuditRun
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT 1',
                AuditSchema::runsTable(),
                AuditRunStatus::Complete->value
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrateRun($row) : null;
    }

    public function replaceFindingsForPosts(int $runId, array $postIds, array $findings): void
    {
        global $wpdb;

        $postIds = array_values(array_filter(array_map('intval', $postIds)));
        foreach ($postIds as $postId) {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE run_id = %d AND post_id = %d',
                    AuditSchema::findingsTable(),
                    $runId,
                    $postId
                )
            );
        }

        foreach ($findings as $finding) {
            $wpdb->insert(
                AuditSchema::findingsTable(),
                array(
                    'run_id'        => $runId,
                    'post_id'       => $finding->postId,
                    'post_type'     => $finding->postType,
                    'rule_id'       => (string) $finding->ruleId,
                    'field_key'     => $finding->fieldKey,
                    'validation_id' => $finding->validationId,
                    'code'          => $finding->code,
                    'severity'      => $finding->severity->value,
                    'message'       => $finding->message,
                    'created_at'    => $finding->createdAt,
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }
    }

    public function findFindings(int $runId, ?string $severity = null): array
    {
        global $wpdb;

        if ($severity !== null && $severity !== '') {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE run_id = %d AND severity = %s ORDER BY id ASC',
                    AuditSchema::findingsTable(),
                    $runId,
                    $severity
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE run_id = %d ORDER BY id ASC',
                    AuditSchema::findingsTable(),
                    $runId
                ),
                ARRAY_A
            );
        }

        if (!is_array($rows)) {
            return array();
        }

        $findings = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $findings[] = $this->hydrateFinding($row);
            }
        }

        return $findings;
    }

    public function queryFindings(AuditFindingQuery $query): array
    {
        global $wpdb;

        $table    = AuditSchema::findingsTable();
        $runId    = $query->runId;
        $limit    = $query->limit;
        $offset   = $query->offset;
        $severity = $query->severity;
        $ruleId   = $query->ruleId !== null ? (string) $query->ruleId : null;
        $postType = $query->postType;

        if ($severity !== null && $ruleId !== null && $postType !== null) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE run_id = %d AND severity = %s AND rule_id = %s AND post_type = %s ORDER BY id ASC LIMIT %d OFFSET %d',
                    $table,
                    $runId,
                    $severity,
                    $ruleId,
                    $postType,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } elseif ($severity !== null && $ruleId !== null) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE run_id = %d AND severity = %s AND rule_id = %s ORDER BY id ASC LIMIT %d OFFSET %d',
                    $table,
                    $runId,
                    $severity,
                    $ruleId,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } elseif ($severity !== null && $postType !== null) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE run_id = %d AND severity = %s AND post_type = %s ORDER BY id ASC LIMIT %d OFFSET %d',
                    $table,
                    $runId,
                    $severity,
                    $postType,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } elseif ($ruleId !== null && $postType !== null) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE run_id = %d AND rule_id = %s AND post_type = %s ORDER BY id ASC LIMIT %d OFFSET %d',
                    $table,
                    $runId,
                    $ruleId,
                    $postType,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } elseif ($severity !== null) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE run_id = %d AND severity = %s ORDER BY id ASC LIMIT %d OFFSET %d',
                    $table,
                    $runId,
                    $severity,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } elseif ($ruleId !== null) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE run_id = %d AND rule_id = %s ORDER BY id ASC LIMIT %d OFFSET %d',
                    $table,
                    $runId,
                    $ruleId,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } elseif ($postType !== null) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE run_id = %d AND post_type = %s ORDER BY id ASC LIMIT %d OFFSET %d',
                    $table,
                    $runId,
                    $postType,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE run_id = %d ORDER BY id ASC LIMIT %d OFFSET %d',
                    $table,
                    $runId,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        }

        if (!is_array($rows)) {
            return array();
        }

        $findings = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $findings[] = $this->hydrateFinding($row);
            }
        }

        return $findings;
    }

    public function countFindings(AuditFindingQuery $query): int
    {
        global $wpdb;

        $table    = AuditSchema::findingsTable();
        $runId    = $query->runId;
        $severity = $query->severity;
        $ruleId   = $query->ruleId !== null ? (string) $query->ruleId : null;
        $postType = $query->postType;

        if ($severity !== null && $ruleId !== null && $postType !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE run_id = %d AND severity = %s AND rule_id = %s AND post_type = %s',
                    $table,
                    $runId,
                    $severity,
                    $ruleId,
                    $postType
                )
            );
        }

        if ($severity !== null && $ruleId !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE run_id = %d AND severity = %s AND rule_id = %s',
                    $table,
                    $runId,
                    $severity,
                    $ruleId
                )
            );
        }

        if ($severity !== null && $postType !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE run_id = %d AND severity = %s AND post_type = %s',
                    $table,
                    $runId,
                    $severity,
                    $postType
                )
            );
        }

        if ($ruleId !== null && $postType !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE run_id = %d AND rule_id = %s AND post_type = %s',
                    $table,
                    $runId,
                    $ruleId,
                    $postType
                )
            );
        }

        if ($severity !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE run_id = %d AND severity = %s',
                    $table,
                    $runId,
                    $severity
                )
            );
        }

        if ($ruleId !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE run_id = %d AND rule_id = %s',
                    $table,
                    $runId,
                    $ruleId
                )
            );
        }

        if ($postType !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE run_id = %d AND post_type = %s',
                    $table,
                    $runId,
                    $postType
                )
            );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE run_id = %d',
                $table,
                $runId
            )
        );
    }

    public function countDistinctPosts(AuditFindingQuery $query): int
    {
        global $wpdb;

        $table    = AuditSchema::findingsTable();
        $runId    = $query->runId;
        $severity = $query->severity;
        $ruleId   = $query->ruleId !== null ? (string) $query->ruleId : null;
        $postType = $query->postType;

        if ($severity !== null && $ruleId !== null && $postType !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(DISTINCT post_id) FROM %i WHERE run_id = %d AND severity = %s AND rule_id = %s AND post_type = %s',
                    $table,
                    $runId,
                    $severity,
                    $ruleId,
                    $postType
                )
            );
        }

        if ($severity !== null && $ruleId !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(DISTINCT post_id) FROM %i WHERE run_id = %d AND severity = %s AND rule_id = %s',
                    $table,
                    $runId,
                    $severity,
                    $ruleId
                )
            );
        }

        if ($severity !== null && $postType !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(DISTINCT post_id) FROM %i WHERE run_id = %d AND severity = %s AND post_type = %s',
                    $table,
                    $runId,
                    $severity,
                    $postType
                )
            );
        }

        if ($ruleId !== null && $postType !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(DISTINCT post_id) FROM %i WHERE run_id = %d AND rule_id = %s AND post_type = %s',
                    $table,
                    $runId,
                    $ruleId,
                    $postType
                )
            );
        }

        if ($severity !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(DISTINCT post_id) FROM %i WHERE run_id = %d AND severity = %s',
                    $table,
                    $runId,
                    $severity
                )
            );
        }

        if ($ruleId !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(DISTINCT post_id) FROM %i WHERE run_id = %d AND rule_id = %s',
                    $table,
                    $runId,
                    $ruleId
                )
            );
        }

        if ($postType !== null) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(DISTINCT post_id) FROM %i WHERE run_id = %d AND post_type = %s',
                    $table,
                    $runId,
                    $postType
                )
            );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT post_id) FROM %i WHERE run_id = %d',
                $table,
                $runId
            )
        );
    }

    /**
     * @return array{fail: int, warning: int}
     */
    public function countFindingsBySeverity(int $runId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT severity, COUNT(*) AS finding_count FROM %i WHERE run_id = %d GROUP BY severity',
                AuditSchema::findingsTable(),
                $runId
            ),
            ARRAY_A
        );

        $counts = array(
            'fail'    => 0,
            'warning' => 0,
        );

        if (!is_array($rows)) {
            return $counts;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $severity = (string) ($row['severity'] ?? '');
            if ($severity === 'fail' || $severity === 'warning') {
                $counts[$severity] = (int) ($row['finding_count'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * @return AuditRuleImpact[]
     */
    public function countFindingsByRule(int $runId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT rule_id, COUNT(*) AS finding_count, COUNT(DISTINCT post_id) AS post_count FROM %i WHERE run_id = %d GROUP BY rule_id',
                AuditSchema::findingsTable(),
                $runId
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array();
        }

        $impacts = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $impacts[] = new AuditRuleImpact(
                (string) ($row['rule_id'] ?? ''),
                (int) ($row['finding_count'] ?? 0),
                (int) ($row['post_count'] ?? 0)
            );
        }

        return $impacts;
    }

    /**
     * @return AuditRun[]
     */
    public function findRecentRuns(int $limit, int $offset = 0): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
                AuditSchema::runsTable(),
                max(1, $limit),
                max(0, $offset)
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return array();
        }

        $runs = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $runs[] = $this->hydrateRun($row);
            }
        }

        return $runs;
    }

    public function countRuns(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i',
                AuditSchema::runsTable()
            )
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRun(array $row): AuditRun
    {
        $types = json_decode((string) ($row['post_types'] ?? '[]'), true);
        if (!is_array($types)) {
            $types = array();
        }

        return new AuditRun(
            (int) $row['id'],
            AuditRunStatus::from((string) $row['status']),
            (string) $row['started_at'],
            isset($row['finished_at']) && $row['finished_at'] !== null && $row['finished_at'] !== ''
                ? (string) $row['finished_at']
                : null,
            (string) $row['heartbeat_at'],
            (int) $row['posts_scanned'],
            (int) $row['posts_passed'],
            (int) $row['posts_warned'],
            (int) $row['posts_failed'],
            (int) $row['posts_not_evaluated'],
            (int) $row['posts_total'],
            (int) ($row[AuditSchema::CURSOR_COLUMN] ?? $row['cursor'] ?? 0),
            (int) $row['actor_user_id'],
            array_values(array_map('strval', $types)),
            isset($row['error_message']) && $row['error_message'] !== null && $row['error_message'] !== ''
                ? (string) $row['error_message']
                : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateFinding(array $row): AuditFinding
    {
        $message = (string) $row['message'];

        return new AuditFinding(
            (int) $row['id'],
            (int) $row['run_id'],
            (int) $row['post_id'],
            (string) $row['post_type'],
            $row['rule_id'],
            (string) $row['field_key'],
            (string) ($row['validation_id'] ?? ''),
            (string) ($row['code'] ?? ''),
            RuleSeverity::from((string) $row['severity']),
            $message,
            (string) $row['created_at'],
            AuditRepeaterCoordinates::contextFromCells(
                AuditRepeaterCoordinates::cellsFromSnapshot($message)
            ),
        );
    }

    private function logDbError(string $message): void
    {
        global $wpdb;

        if (!defined('WP_DEBUG') || !WP_DEBUG || !function_exists('error_log')) {
            return;
        }

        $detail = isset($wpdb) && is_object($wpdb) && isset($wpdb->last_error)
            ? (string) $wpdb->last_error
            : '';

        error_log('ContentLatch audit persistence failed: ' . $message . ($detail !== '' ? ' ' . $detail : ''));
    }
}
