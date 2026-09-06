<?php
/**
 * Field is empty.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Operators;

use ContentGuard\Domain\Contracts\OperatorInterface;
use ContentGuard\Domain\Value;

final class IsEmptyOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        unset($operand);

        return Value::isEmpty($value);
    }
}
