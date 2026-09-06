<?php
/**
 * Audit run lifecycle status.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Audit;

enum AuditRunStatus: string
{
    case Pending   = 'pending';
    case Running   = 'running';
    case Complete  = 'complete';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }

    public function isTerminal(): bool
    {
        return !$this->isActive();
    }
}
