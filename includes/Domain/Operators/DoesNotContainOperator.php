<?php
/**
 * Field does not contain operand (inverse of contains).
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Operators;

use ContentLatch\Domain\Contracts\OperatorInterface;
use ContentLatch\Domain\Value;

final class DoesNotContainOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        return !Value::contains($value, $operand);
    }
}
