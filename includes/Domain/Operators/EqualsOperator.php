<?php
/**
 * Field equals operand (case-sensitive scalar comparison).
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Operators;

use ContentGuard\Domain\Contracts\OperatorInterface;
use ContentGuard\Domain\Value;

final class EqualsOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        return Value::equals($value, $operand);
    }
}
