<?php
/**
 * Field is not empty.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Operators;

use ContentLatch\Domain\Contracts\OperatorInterface;
use ContentLatch\Domain\Value;

final class IsNotEmptyOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        unset($operand);

        return !Value::isEmpty($value);
    }
}
