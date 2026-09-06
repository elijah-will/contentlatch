<?php
/**
 * Field does not equal operand.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Operators;

use ContentGuard\Domain\Contracts\OperatorInterface;
use ContentGuard\Domain\Value;

final class NotEqualsOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        return !Value::equals($value, $operand);
    }
}
