<?php
/**
 * Structured result for one skipped rule, one validation, or one
 * condition-only match.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain;

final class EvaluationResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly EvaluationStatus $status,
        public readonly int|string $ruleId,
        public readonly ?int $postId,
        public readonly ?string $fieldId,
        public readonly string $message,
        public readonly RuleSeverity $severity,
        public readonly string $code,
        public readonly array $context = array(),
    ) {
    }

    public function isSkipped(): bool
    {
        return $this->status === EvaluationStatus::Skipped;
    }

    public function isPassed(): bool
    {
        return $this->status === EvaluationStatus::Passed;
    }

    public function isWarning(): bool
    {
        return $this->status === EvaluationStatus::Warning;
    }

    public function isFailed(): bool
    {
        return $this->status === EvaluationStatus::Failed;
    }
}
