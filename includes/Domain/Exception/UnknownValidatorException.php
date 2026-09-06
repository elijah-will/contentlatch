<?php
/**
 * Unknown validator identifier.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Exception;

use InvalidArgumentException;

final class UnknownValidatorException extends InvalidArgumentException
{
    public function __construct(string $validator)
    {
        parent::__construct(sprintf('Unknown validation type "%s".', $validator));
    }
}
