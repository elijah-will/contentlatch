<?php
/**
 * Finding and affected-post counts for one rule in a run.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Audit;

defined('ABSPATH') || exit;

final class AuditRuleImpact
{
    public function __construct(
        public readonly int|string $ruleId,
        public readonly int $findingCount,
        public readonly int $postCount,
    ) {
    }
}
