<?php
/**
 * Unauthorized rule mutation.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Exception;

use RuntimeException;

final class ForbiddenRuleMutationException extends RuntimeException
{
}
