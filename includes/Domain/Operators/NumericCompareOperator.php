<?php
/**
 * Numeric comparison of a field value against an operand.
 *
 * Invalid or empty values do not become zero; the comparison fails instead.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Operators;

use ContentLatch\Domain\Contracts\OperatorInterface;
use ContentLatch\Domain\Value;

final class NumericCompareOperator implements OperatorInterface
{
    public function __construct(private string $comparison)
    {
    }

    public function matches(mixed $value, mixed $operand): bool
    {
        $left  = Value::tryNumber($value);
        $right = Value::tryNumber($operand);
        if ($left === null || $right === null) {
            return false;
        }

        return match ($this->comparison) {
            '>'  => $left > $right,
            '>=' => $left >= $right,
            '<'  => $left < $right,
            '<=' => $left <= $right,
            default => false,
        };
    }
}
