<?php
/**
 * In-memory audit persistence for tests.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Support;

use ContentGuard\Application\Audit\AuditFinding;
use ContentGuard\Application\Audit\AuditFindingQuery;
use ContentGuard\Application\Audit\AuditRuleImpact;
use ContentGuard\Application\Audit\AuditRun;
use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Application\Audit\AuditStoreInterface;

final class InMemoryAuditStore implements AuditStoreInterface
{
    /**
     * @var array<int, AuditRun>
     */
    public array $runs = array();

    /**
     * @var array<int, AuditFinding>
     */
    public array $findings = array();

    public int $nextRunId = 1;

    public int $nextFindingId = 1;

    public bool $failNextFindingWrite = false;

    /**
     * @param string[] $postTypes
     */
    public function insertRun(
        int $actorUserId,
        array $postTypes,
        int $postsTotal,
        string $startedAt,
    ): AuditRun {
        $id  = $this->nextRunId++;
        $run = new AuditRun(
            $id,
            AuditRunStatus::Pending,
            $startedAt,
            null,
            $startedAt,
            0,
            0,
            0,
            0,
            0,
            $postsTotal,
            0,
            $actorUserId,
            $postTypes,
            null,
        );
        $this->runs[$id] = $run;

        return $run;
    }

    public function saveRun(AuditRun $run): AuditRun
    {
        $this->runs[$run->id] = $run;

        return $run;
    }

    public function findRun(int $id): ?AuditRun
    {
        return $this->runs[$id] ?? null;
    }

    public function findActiveRun(): ?AuditRun
    {
        foreach ($this->runs as $run) {
            if ($run->isActive()) {
                return $run;
            }
        }

        return null;
    }

    public function findLatestRun(): ?AuditRun
    {
        if ($this->runs === array()) {
            return null;
        }

        return $this->runs[array_key_last($this->runs)];
    }

    public function findLatestCompleteRun(): ?AuditRun
    {
        $latest = null;

        foreach ($this->runs as $run) {
            if ($run->status === AuditRunStatus::Complete) {
                $latest = $run;
            }
        }

        return $latest;
    }

    public function replaceFindingsForPosts(int $runId, array $postIds, array $findings): void
    {
        if ($this->failNextFindingWrite) {
            $this->failNextFindingWrite = false;
            throw new \RuntimeException('Finding write failed.');
        }

        $keep = array();
        foreach ($this->findings as $finding) {
            if ($finding->runId !== $runId || !in_array($finding->postId, $postIds, true)) {
                $keep[] = $finding;
            }
        }

        $this->findings = $keep;

        $seen = array();
        foreach ($findings as $finding) {
            $key = implode(
                ':',
                array(
                    (string) $runId,
                    (string) $finding->postId,
                    (string) $finding->ruleId,
                    $finding->fieldKey,
                    $finding->validationId,
                )
            );
            $seen[$key] = new AuditFinding(
                $this->nextFindingId++,
                $runId,
                $finding->postId,
                $finding->postType,
                $finding->ruleId,
                $finding->fieldKey,
                $finding->validationId,
                $finding->code,
                $finding->severity,
                $finding->message,
                $finding->createdAt,
                $finding->context,
            );
        }

        foreach ($seen as $finding) {
            $this->findings[] = $finding;
        }
    }

    public function findFindings(int $runId, ?string $severity = null): array
    {
        $matches = array();

        foreach ($this->findings as $finding) {
            if ($finding->runId !== $runId) {
                continue;
            }

            if ($severity !== null && $finding->severity->value !== $severity) {
                continue;
            }

            $matches[] = $finding;
        }

        return $matches;
    }

    public function queryFindings(AuditFindingQuery $query): array
    {
        $matches = $this->matchingFindings($query);

        return array_slice($matches, $query->offset, $query->limit);
    }

    public function countFindings(AuditFindingQuery $query): int
    {
        return count($this->matchingFindings($query));
    }

    public function countDistinctPosts(AuditFindingQuery $query): int
    {
        $posts = array();

        foreach ($this->matchingFindings($query) as $finding) {
            $posts[(string) $finding->postId] = true;
        }

        return count($posts);
    }

    /**
     * @return array{fail: int, warning: int}
     */
    public function countFindingsBySeverity(int $runId): array
    {
        $counts = array(
            'fail'    => 0,
            'warning' => 0,
        );

        foreach ($this->findings as $finding) {
            if ($finding->runId !== $runId) {
                continue;
            }

            $counts[$finding->severity->value] = ($counts[$finding->severity->value] ?? 0) + 1;
        }

        return array(
            'fail'    => $counts['fail'],
            'warning' => $counts['warning'],
        );
    }

    /**
     * @return AuditRuleImpact[]
     */
    public function countFindingsByRule(int $runId): array
    {
        $findings = array();
        $posts    = array();

        foreach ($this->findings as $finding) {
            if ($finding->runId !== $runId) {
                continue;
            }

            $key = (string) $finding->ruleId;
            $findings[$key] = ($findings[$key] ?? 0) + 1;
            $posts[$key][(string) $finding->postId] = true;
        }

        $impacts = array();
        foreach ($findings as $ruleId => $findingCount) {
            $impacts[] = new AuditRuleImpact($ruleId, $findingCount, count($posts[$ruleId] ?? array()));
        }

        return $impacts;
    }

    /**
     * @return AuditRun[]
     */
    public function findRecentRuns(int $limit, int $offset = 0): array
    {
        $runs = array_values($this->runs);
        usort(
            $runs,
            static fn (AuditRun $left, AuditRun $right): int => $right->id <=> $left->id
        );

        return array_slice($runs, max(0, $offset), max(1, $limit));
    }

    public function countRuns(): int
    {
        return count($this->runs);
    }

    /**
     * @return AuditFinding[]
     */
    private function matchingFindings(AuditFindingQuery $query): array
    {
        $matches = array();

        foreach ($this->findings as $finding) {
            if ($query->matches($finding)) {
                $matches[] = $finding;
            }
        }

        return $matches;
    }
}
