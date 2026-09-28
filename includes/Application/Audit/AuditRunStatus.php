<?php
/**
 * Audit run lifecycle status.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Audit;

defined('ABSPATH') || exit;

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
