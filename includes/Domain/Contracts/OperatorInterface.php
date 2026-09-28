<?php
/**
 * Condition operator.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Contracts;

interface OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool;
}
