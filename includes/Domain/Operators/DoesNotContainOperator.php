<?php
/**
 * Field does not contain operand (inverse of contains).
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Operators;

use ContentGuard\Domain\Contracts\OperatorInterface;
use ContentGuard\Domain\Value;

final class DoesNotContainOperator implements OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool
    {
        return !Value::contains($value, $operand);
    }
}
