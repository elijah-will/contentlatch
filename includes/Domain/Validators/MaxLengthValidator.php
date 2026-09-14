<?php
/**
 * Maximum string length.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Validators;

use ContentGuard\Domain\Contracts\ValidatorInterface;
use ContentGuard\Domain\Text;
use ContentGuard\Domain\ValidatorOutcome;
use ContentGuard\Domain\Value;

final class MaxLengthValidator implements ValidatorInterface
{
    public function validate(mixed $value, array $params): ValidatorOutcome
    {
        if (!array_key_exists('max', $params) || !is_numeric($params['max']) || (int) $params['max'] < 0) {
            return ValidatorOutcome::fail(
                'invalid_params',
                Text::translate('Maximum length is not configured.'),
            );
        }

        $max    = (int) $params['max'];
        $length = Value::stringLength($value);

        if ($length === null) {
            return ValidatorOutcome::fail(
                'invalid_type',
                Text::translate('This field cannot be measured as text.'),
                array('max' => $max),
            );
        }

        if ($length > $max) {
            return ValidatorOutcome::fail(
                'max_length',
                Text::sprintf('This field must be at most %d characters.', $max),
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
