<?php
/**
 * $wpdb-backed audit persistence.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

defined('ABSPATH') || exit;

use ContentGuard\Application\Audit\AuditFinding;
use ContentGuard\Application\Audit\AuditFindingQuery;
use ContentGuard\Application\Audit\AuditRepeaterCoordinates;
use ContentGuard\Application\Audit\AuditRuleImpact;
use ContentGuard\Application\Audit\AuditRun;
use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Application\Audit\AuditStoreInterface;
use ContentGuard\Application\Exception\AuditException;
use ContentGuard\Domain\RuleSeverity;

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

        $finishedSql = $run->finishedAt === null ? 'NULL' : '%s';
        $errorSql    = $run->errorMessage === null ? 'NULL' : '%s';
        $sql         = 'UPDATE ' . AuditSchema::runsTable() . " SET
status = %s,
finished_at = {$finishedSql},
heartbeat_at = %s,
posts_scanned = %d,
posts_passed = %d,
posts_warned = %d,
posts_failed = %d,
posts_not_evaluated = %d,
posts_total = %d,
" . AuditSchema::CURSOR_COLUMN . " = %d,
actor_user_id = %d,
post_types = %s,
error_message = {$errorSql}
WHERE id = %d";

        $args = array($run->status->value);
        if ($run->finishedAt !== null) {
            $args[] = $run->finishedAt;
        }

        array_push(
            $args,
            $run->heartbeatAt,
            $run->postsScanned,
            $run->postsPassed,
            $run->postsWarned,
            $run->postsFailed,
            $run->postsNotEvaluated,
            $run->postsTotal,
            $run->cursor,
            $run->actorUserId,
            wp_json_encode(array_values($run->postTypes))
        );

        if ($run->errorMessage !== null) {
            $args[] = $run->errorMessage;
        }

        $args[] = $run->id;

        $updated = $wpdb->query($wpdb->prepare($sql, ...$args));
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
                'SELECT * FROM ' . AuditSchema::runsTable() . ' WHERE id = %d',
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
                'SELECT * FROM ' . AuditSchema::runsTable() . ' WHERE status IN (%s, %s) ORDER BY id ASC LIMIT 1',
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
            'SELECT * FROM ' . AuditSchema::runsTable() . ' ORDER BY id DESC LIMIT 1',
            ARRAY_A
        );

        return is_array($row) ? $this->hydrateRun($row) : null;
    }

    public function findLatestCompleteRun(): ?AuditRun
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . AuditSchema::runsTable() . ' WHERE status = %s ORDER BY id DESC LIMIT 1',
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
        if ($postIds !== array()) {
            $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . AuditSchema::findingsTable() . ' WHERE run_id = %d AND post_id IN (' . $placeholders . ')',
                    array_merge(array($runId), $postIds)
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
                    'SELECT * FROM ' . AuditSchema::findingsTable() . ' WHERE run_id = %d AND severity = %s ORDER BY id ASC',
                    $runId,
                    $severity
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM ' . AuditSchema::findingsTable() . ' WHERE run_id = %d ORDER BY id ASC',
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

        [$where, $args] = $this->findingWhere($query);
        $args[]         = $query->limit;
        $args[]         = $query->offset;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . AuditSchema::findingsTable()
                . ' WHERE ' . $where
                . ' ORDER BY id ASC LIMIT %d OFFSET %d',
                ...$args
            ),
            ARRAY_A
        );

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

        [$where, $args] = $this->findingWhere($query);

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . AuditSchema::findingsTable() . ' WHERE ' . $where,
                ...$args
            )
        );
    }

    public function countDistinctPosts(AuditFindingQuery $query): int
    {
        global $wpdb;

        [$where, $args] = $this->findingWhere($query);

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT post_id) FROM ' . AuditSchema::findingsTable() . ' WHERE ' . $where,
                ...$args
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
                'SELECT severity, COUNT(*) AS finding_count FROM ' . AuditSchema::findingsTable()
                . ' WHERE run_id = %d GROUP BY severity',
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
                'SELECT rule_id, COUNT(*) AS finding_count, COUNT(DISTINCT post_id) AS post_count FROM '
                . AuditSchema::findingsTable()
                . ' WHERE run_id = %d GROUP BY rule_id',
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
                'SELECT * FROM ' . AuditSchema::runsTable() . ' ORDER BY id DESC LIMIT %d OFFSET %d',
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

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AuditSchema::runsTable());
    }

    /**
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function findingWhere(AuditFindingQuery $query): array
    {
        $where = array('run_id = %d');
        $args  = array($query->runId);

        if ($query->severity !== null) {
            $where[] = 'severity = %s';
            $args[]  = $query->severity;
        }

        if ($query->ruleId !== null) {
            $where[] = 'rule_id = %s';
            $args[]  = (string) $query->ruleId;
        }

        if ($query->postType !== null) {
            $where[] = 'post_type = %s';
            $args[]  = $query->postType;
        }

        return array(implode(' AND ', $where), $args);
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

        error_log('ContentGuard audit persistence failed: ' . $message . ($detail !== '' ? ' ' . $detail : ''));
    }
}
