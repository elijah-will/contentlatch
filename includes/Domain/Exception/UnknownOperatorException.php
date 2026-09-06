<?php
/**
 * Unknown operator identifier.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Exception;

use InvalidArgumentException;

final class UnknownOperatorException extends InvalidArgumentException
{
    public function __construct(string $operator)
    {
        parent::__construct(sprintf('Unknown condition operator "%s".', $operator));
    }
}
