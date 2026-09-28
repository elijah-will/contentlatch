<?php
/**
 * Audit request or state error.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Exception;

defined('ABSPATH') || exit;

use RuntimeException;

final class AuditException extends RuntimeException
{
}
