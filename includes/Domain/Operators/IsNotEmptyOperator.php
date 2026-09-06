<?php
/**
 * Field is not empty.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Operators;

use ContentGuard\Domain\Contracts\OperatorInterface;
use ContentGuard\Domain\Value;

final class IsNotEmptyOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        unset($operand);

        return !Value::isEmpty($value);
    }
}
