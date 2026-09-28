<?php
/**
 * Unknown validator identifier.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Exception;

use InvalidArgumentException;

final class UnknownValidatorException extends InvalidArgumentException
{
    public function __construct(string $validator)
    {
        parent::__construct(sprintf('Unknown validation type "%s".', $validator));
    }
}
