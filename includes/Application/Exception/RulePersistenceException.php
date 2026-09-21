<?php
/**
 * Rule could not be stored.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Exception;

defined('ABSPATH') || exit;

use RuntimeException;

final class RulePersistenceException extends RuntimeException
{
}
