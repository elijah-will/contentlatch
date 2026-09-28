<?php
/**
 * Maximum string length.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Domain\Validators;

use ContentLatch\Domain\Contracts\ValidatorInterface;
use ContentLatch\Domain\ValidatorOutcome;
use ContentLatch\Domain\Value;

final class MaxLengthValidator implements ValidatorInterface
{
    public function validate(mixed $value, array $params): ValidatorOutcome
    {
        if (!array_key_exists('max', $params) || !is_numeric($params['max']) || (int) $params['max'] < 0) {
            return ValidatorOutcome::fail(
                'invalid_params',
                'Maximum length is not configured.',
            );
        }

        $max    = (int) $params['max'];
        $length = Value::stringLength($value);

        if ($length === null) {
            return ValidatorOutcome::fail(
                'invalid_type',
                'This field cannot be measured as text.',
                array('max' => $max),
            );
        }

        if ($length > $max) {
            return ValidatorOutcome::fail(
                'max_length',
                sprintf('This field must be at most %d characters.', $max),
                array(
                    'max'    => $max,
                    'length' => $length,
                ),
            );
        }

        return ValidatorOutcome::pass(
            'max_length',
            array(
                'max'    => $max,
                'length' => $length,
            )
        );
    }
}
