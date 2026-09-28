<?php
/**
 * Rule could not be stored.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Exception;

defined('ABSPATH') || exit;

use RuntimeException;

final class RulePersistenceException extends RuntimeException
{
}
