<?php
/**
 * Field contains operand (case-insensitive literal substring).
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Operators;

use ContentGuard\Domain\Contracts\OperatorInterface;
use ContentGuard\Domain\Value;

final class ContainsOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        return Value::contains($value, $operand);
    }
}
