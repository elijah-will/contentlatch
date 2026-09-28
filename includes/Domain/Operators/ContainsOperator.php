<?php
/**
 * Field contains operand (case-insensitive literal substring).
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Operators;

use ContentLatch\Domain\Contracts\OperatorInterface;
use ContentLatch\Domain\Value;

final class ContainsOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        return Value::contains($value, $operand);
    }
}
