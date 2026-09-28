<?php
/**
 * In-memory audit start lock for tests.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Support;

use ContentLatch\Application\Audit\AuditLockInterface;

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
