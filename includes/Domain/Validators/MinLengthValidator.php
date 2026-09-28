<?php
/**
 * Minimum string length.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Validators;

use ContentLatch\Domain\Contracts\ValidatorInterface;
use ContentLatch\Domain\ValidatorOutcome;
use ContentLatch\Domain\Value;

final class MinLengthValidator implements ValidatorInterface
{
    public function validate(mixed $value, array $params): ValidatorOutcome
    {
        if (!array_key_exists('min', $params) || !is_numeric($params['min']) || (int) $params['min'] < 0) {
            return ValidatorOutcome::fail(
                'invalid_params',
                'Minimum length is not configured.',
            );
        }

        $min    = (int) $params['min'];
        $length = Value::stringLength($value);

        if ($length === null) {
            return ValidatorOutcome::fail(
                'invalid_type',
                'This field cannot be measured as text.',
                array('min' => $min),
            );
        }

        if ($length < $min) {
            return ValidatorOutcome::fail(
                'min_length',
                sprintf('This field must be at least %d characters.', $min),
                array(
                    'min'    => $min,
                    'length' => $length,
                ),
            );
        }

        return ValidatorOutcome::pass(
            'min_length',
            array(
                'min'    => $min,
                'length' => $length,
            )
        );
    }
}
