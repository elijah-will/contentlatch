<?php
/**
 * Atomic audit start lock via add_option().
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

use ContentLatch\Application\Audit\AuditLockInterface;

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
