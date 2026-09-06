<?php
/**
 * Atomic lock so only one active audit can be created.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Audit;

interface AuditLockInterface
{
    public function acquire(): bool;

    public function release(): void;
}
