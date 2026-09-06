<?php
/**
 * In-memory audit start lock for tests.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Support;

use ContentGuard\Application\Audit\AuditLockInterface;

final class InMemoryAuditLock implements AuditLockInterface
{
    public bool $held = false;

    public int $acquireCalls = 0;

    public function acquire(): bool
    {
        ++$this->acquireCalls;
        if ($this->held) {
            return false;
        }

        $this->held = true;

        return true;
    }

    public function release(): void
    {
        $this->held = false;
    }
}
