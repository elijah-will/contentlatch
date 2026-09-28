<?php
/**
 * Unauthorized rule mutation.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Exception;

defined('ABSPATH') || exit;

use RuntimeException;

final class ForbiddenRuleMutationException extends RuntimeException
{
}
