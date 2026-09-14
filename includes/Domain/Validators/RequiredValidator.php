<?php
/**
 * Field is required (not empty).
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Validators;

use ContentGuard\Domain\Contracts\ValidatorInterface;
use ContentGuard\Domain\Text;
use ContentGuard\Domain\ValidatorOutcome;
use ContentGuard\Domain\Value;

final class RequiredValidator implements ValidatorInterface
{
    public function validate(mixed $value, array $params): ValidatorOutcome
    {
        unset($params);

        if (Value::isEmpty($value)) {
            return ValidatorOutcome::fail(
                'required',
                Text::translate('This field is required.'),
            );
        }

        return ValidatorOutcome::pass('required');
    }
}
