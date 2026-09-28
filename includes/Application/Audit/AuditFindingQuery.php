<?php
/**
 * Filter and pagination for audit findings.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Audit;

defined('ABSPATH') || exit;

final class AuditFindingQuery
{
    public const DEFAULT_LIMIT = 50;

    public readonly ?string $severity;

    public readonly int|string|null $ruleId;

    public readonly ?string $postType;

    public readonly int $limit;

    public readonly int $offset;

    public function __construct(
        public readonly int $runId,
        ?string $severity = null,
        int|string|null $ruleId = null,
        ?string $postType = null,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
    ) {
        $this->severity = ($severity === 'fail' || $severity === 'warning') ? $severity : null;
        $this->ruleId   = self::normalizeRuleId($ruleId);
        $this->postType = ($postType !== null && $postType !== '') ? $postType : null;
        $this->limit    = max(1, $limit);
        $this->offset   = max(0, $offset);
    }

    public function hasFilters(): bool
    {
        return $this->severity !== null || $this->ruleId !== null || $this->postType !== null;
    }

    public function matches(AuditFinding $finding): bool
    {
        if ($finding->runId !== $this->runId) {
            return false;
        }

        if ($this->severity !== null && $finding->severity->value !== $this->severity) {
            return false;
        }

        if ($this->ruleId !== null && (string) $finding->ruleId !== (string) $this->ruleId) {
            return false;
        }

        if ($this->postType !== null && $finding->postType !== $this->postType) {
            return false;
        }

        return true;
    }

    private static function normalizeRuleId(int|string|null $ruleId): int|string|null
    {
        if ($ruleId === null || $ruleId === '' || $ruleId === 0 || $ruleId === '0') {
            return null;
        }

        return $ruleId;
    }
}
