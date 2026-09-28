<?php
/**
 * Field equals operand (case-sensitive scalar comparison).
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Operators;

use ContentLatch\Domain\Contracts\OperatorInterface;
use ContentLatch\Domain\Value;

final class EqualsOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        return Value::equals($value, $operand);
    }
}
