<?php
/**
 * Persistence port for audit runs and findings.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Audit;

interface AuditStoreInterface
{
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
