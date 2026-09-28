<?php
/**
 * Atomic lock so only one active audit can be created.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Audit;

defined('ABSPATH') || exit;

interface AuditLockInterface
{
    public function acquire(): bool;

    public function release(): void;
}
