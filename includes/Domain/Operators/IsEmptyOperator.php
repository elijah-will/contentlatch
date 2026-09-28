<?php
/**
 * Field is empty.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Operators;

use ContentLatch\Domain\Contracts\OperatorInterface;
use ContentLatch\Domain\Value;

final class IsEmptyOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        unset($operand);

        return Value::isEmpty($value);
    }
}
