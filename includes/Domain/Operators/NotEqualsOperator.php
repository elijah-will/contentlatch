<?php
/**
 * Field does not equal operand.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Operators;

use ContentLatch\Domain\Contracts\OperatorInterface;
use ContentLatch\Domain\Value;

final class NotEqualsOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        return !Value::equals($value, $operand);
    }
}
