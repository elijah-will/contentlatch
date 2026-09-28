<?php
/**
 * Field validator.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Contracts;

use ContentLatch\Domain\ValidatorOutcome;

interface ValidatorInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function validate(mixed $value, array $params): ValidatorOutcome;
}
