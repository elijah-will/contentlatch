<?php
/**
 * Field validator.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Contracts;

use ContentGuard\Domain\ValidatorOutcome;

interface ValidatorInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function validate(mixed $value, array $params): ValidatorOutcome;
}
