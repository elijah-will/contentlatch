<?php
/**
 * Value must be one of a defined set.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Domain\Validators;

use ContentGuard\Domain\Contracts\ValidatorInterface;
use ContentGuard\Domain\Text;
use ContentGuard\Domain\ValidatorOutcome;
use ContentGuard\Domain\Value;

final class AllowedValuesValidator implements ValidatorInterface
{
    public function validate(mixed $value, array $params): ValidatorOutcome
    {
        $allowed = $params['values'] ?? null;

        if (!is_array($allowed) || $allowed === array()) {
            return ValidatorOutcome::fail(
                'invalid_params',
                Text::translate('Allowed values are not configured.'),
            );
        }

        if (Value::isEmpty($value)) {
            if (self::allowsEmpty($allowed)) {
                return ValidatorOutcome::pass(
                    'allowed_values',
                    array('values' => $allowed)
                );
            }

            return ValidatorOutcome::fail(
                'allowed_values',
                Text::translate('This field must be one of the allowed values.'),
                array('values' => $allowed),
            );
        }

        $comparable = Value::toComparableString($value);

        if ($comparable === null) {
            return ValidatorOutcome::fail(
                'invalid_type',
                Text::translate('This field cannot be compared to allowed values.'),
                array('values' => $allowed),
            );
        }

        foreach ($allowed as $candidate) {
            if (Value::equals($value, $candidate)) {
                return ValidatorOutcome::pass(
                    'allowed_values',
                    array(
                        'values' => $allowed,
                        'actual' => $comparable,
                    )
                );
            }
        }

        return ValidatorOutcome::fail(
            'allowed_values',
            'This field must be one of the allowed values.',
            array(
                'values' => $allowed,
                'actual' => $comparable,
            ),
        );
    }

    /**
     * @param array<int, mixed> $allowed
     */
    private static function allowsEmpty(array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            if (Value::isEmpty($candidate)) {
                return true;
            }
        }

        return false;
    }
}
