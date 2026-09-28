<?php
/**
 * Field is required (not empty).
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Validators;

use ContentLatch\Domain\Contracts\ValidatorInterface;
use ContentLatch\Domain\ValidatorOutcome;
use ContentLatch\Domain\Value;

final class RequiredValidator implements ValidatorInterface
{
    public function validate(mixed $value, array $params): ValidatorOutcome
    {
        unset($params);

        if (Value::isEmpty($value)) {
            return ValidatorOutcome::fail(
                'required',
                'This field is required.',
            );
        }

        return ValidatorOutcome::pass('required');
    }
}
