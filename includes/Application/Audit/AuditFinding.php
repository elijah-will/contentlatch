<?php
/**
 * Persisted fail or warning from one audit evaluation.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Audit;

defined('ABSPATH') || exit;

use ContentLatch\Domain\RuleSeverity;

final class AuditFinding
{
    /**
     * @param array<string, mixed> $context Collapsed evaluation context. Nested
     *                                      Repeater cells keep their instance
     *                                      repeater_rows chains here.
     */
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
        public readonly array $context = array(),
    ) {
    }
}
