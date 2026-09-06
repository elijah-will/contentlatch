<?php
/**
 * Aggregate evaluation of one content item against a set of rules.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain;

final class ContentEvaluation
{
    /**
     * @param EvaluationResult[] $results
     */
    public function __construct(
        public readonly ?int $postId,
        public readonly ContentStatus $status,
        public readonly array $results,
        public readonly ?string $postType = null,
    ) {
    }

    /**
     * @param EvaluationResult[] $results
     */
    public static function fromResults(?int $postId, array $results, ?string $postType = null): self
    {
        $hasFailed  = false;
        $hasWarning = false;
        $hasPassed  = false;

        foreach ($results as $result) {
            if (!$result instanceof EvaluationResult) {
                continue;
            }

            if ($result->isFailed()) {
                $hasFailed = true;
            } elseif ($result->isWarning()) {
                $hasWarning = true;
            } elseif ($result->isPassed()) {
                $hasPassed = true;
            }
        }

        $status = match (true) {
            $hasFailed  => ContentStatus::Failed,
            $hasWarning => ContentStatus::Warning,
            $hasPassed  => ContentStatus::Passed,
            default     => ContentStatus::NotEvaluated,
        };

        return new self($postId, $status, $results, $postType);
    }

    public function withPostType(?string $postType): self
    {
        return new self($this->postId, $this->status, $this->results, $postType);
    }

    public function isPassed(): bool
    {
        return $this->status === ContentStatus::Passed;
    }

    public function isWarning(): bool
    {
        return $this->status === ContentStatus::Warning;
    }

    public function isFailed(): bool
    {
        return $this->status === ContentStatus::Failed;
    }

    public function isNotEvaluated(): bool
    {
        return $this->status === ContentStatus::NotEvaluated;
    }

    /**
     * @return EvaluationResult[]
     */
    public function skippedResults(): array
    {
        return array_values(
            array_filter(
                $this->results,
                static fn (EvaluationResult $result): bool => $result->isSkipped()
            )
        );
    }

    /**
     * @return EvaluationResult[]
     */
    public function appliedResults(): array
    {
        return array_values(
            array_filter(
                $this->results,
                static fn (EvaluationResult $result): bool => !$result->isSkipped()
            )
        );
    }
}
