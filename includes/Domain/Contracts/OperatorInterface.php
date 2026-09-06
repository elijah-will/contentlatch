<?php
/**
 * Condition operator.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Contracts;

interface OperatorInterface
{
    public function matches(mixed $value, mixed $operand): bool;
}
