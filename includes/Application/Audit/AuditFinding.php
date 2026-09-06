<?php
/**
 * Persisted fail or warning from one audit evaluation.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Audit;

use ContentGuard\Domain\RuleSeverity;

final class AuditFinding
{
    public function __construct(
        public readonly int $id,
        public readonly int $runId,
        public readonly int $postId,
        public readonly string $postType,
        public readonly int|string $ruleId,
        public readonly string $fieldKey,
        public readonly string $validationId,
        public readonly string $code,
        public readonly RuleSeverity $severity,
        public readonly string $message,
        public readonly string $createdAt,
    ) {
    }
}
