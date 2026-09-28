<?php
/**
 * One site-wide audit run.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Audit;

defined('ABSPATH') || exit;

final class AuditRun
{
    /**
     * @param string[] $postTypes
     */
    public function __construct(
        public readonly int $id,
        public readonly AuditRunStatus $status,
        public readonly string $startedAt,
        public readonly ?string $finishedAt,
        public readonly string $heartbeatAt,
        public readonly int $postsScanned,
        public readonly int $postsPassed,
        public readonly int $postsWarned,
        public readonly int $postsFailed,
        public readonly int $postsNotEvaluated,
        public readonly int $postsTotal,
        public readonly int $cursor,
        public readonly int $actorUserId,
        public readonly array $postTypes,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function progressPercent(): ?int
    {
        if ($this->postsTotal <= 0) {
            return $this->status === AuditRunStatus::Complete ? 100 : null;
        }

        return (int) min(100, floor(($this->postsScanned / $this->postsTotal) * 100));
    }

    public function withStatus(
        AuditRunStatus $status,
        ?string $finishedAt = null,
        ?string $errorMessage = null,
    ): self {
        return new self(
            $this->id,
            $status,
            $this->startedAt,
            $finishedAt,
            $this->heartbeatAt,
            $this->postsScanned,
            $this->postsPassed,
            $this->postsWarned,
            $this->postsFailed,
            $this->postsNotEvaluated,
            $this->postsTotal,
            $this->cursor,
            $this->actorUserId,
            $this->postTypes,
            $errorMessage,
        );
    }
}
