<?php
/**
 * Persistence port for audit runs and findings.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Audit;

defined('ABSPATH') || exit;

interface AuditStoreInterface
{
    public const MAX_FINDINGS_BATCH_SIZE = 500;

    /**
     * @param string[] $postTypes
     */
    public function insertRun(
        int $actorUserId,
        array $postTypes,
        int $postsTotal,
        string $startedAt,
    ): AuditRun;

    public function saveRun(AuditRun $run): AuditRun;

    public function findRun(int $id): ?AuditRun;

    public function findActiveRun(): ?AuditRun;

    public function findLatestRun(): ?AuditRun;

    public function findLatestCompleteRun(): ?AuditRun;

    /**
     * Replace findings for the given posts in a run, then persist the new set.
     *
     * @param int[]         $postIds
     * @param AuditFinding[] $findings
     */
    public function replaceFindingsForPosts(int $runId, array $postIds, array $findings): void;

    /**
     * @return AuditFinding[]
     */
    public function findFindings(int $runId, ?string $severity = null): array;

    /**
     * Retrieve one bounded batch in ascending finding-ID order, across severities.
     *
     * Start with afterId 0, then pass the last returned finding ID. Negative
     * cursors become 0; limits are clamped to 1..MAX_FINDINGS_BATCH_SIZE.
     * The caller selects and validates the run (including completed status).
     * An empty array means a successful query found no remaining findings.
     *
     * @return AuditFinding[]
     * @throws \ContentLatch\Application\Exception\AuditException If the query fails.
     */
    public function findFindingsAfterId(int $runId, int $afterId, int $limit): array;

    /**
     * @return AuditFinding[]
     */
    public function queryFindings(AuditFindingQuery $query): array;

    public function countFindings(AuditFindingQuery $query): int;

    public function countDistinctPosts(AuditFindingQuery $query): int;

    /**
     * @return array{fail: int, warning: int}
     */
    public function countFindingsBySeverity(int $runId): array;

    /**
     * @return AuditRuleImpact[]
     */
    public function countFindingsByRule(int $runId): array;

    /**
     * @return AuditRun[]
     */
    public function findRecentRuns(int $limit, int $offset = 0): array;

    public function countRuns(): int;
}
