<?php
/**
 * Atomic audit start lock via add_option().
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

use ContentGuard\Application\Audit\AuditLockInterface;

final class WpAuditLock implements AuditLockInterface
{
    public function acquire(): bool
    {
        return add_option(AuditSchema::LOCK_OPTION, '1', '', false);
    }

    public function release(): void
    {
        delete_option(AuditSchema::LOCK_OPTION);
    }
}
