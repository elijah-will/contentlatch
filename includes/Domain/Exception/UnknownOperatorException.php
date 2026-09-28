<?php
/**
 * Unknown operator identifier.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Exception;

use InvalidArgumentException;

final class UnknownOperatorException extends InvalidArgumentException
{
    public function __construct(string $operator)
    {
        parent::__construct(sprintf('Unknown condition operator "%s".', $operator));
    }
}
