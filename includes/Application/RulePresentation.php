<?php
/**
 * Human-readable rule summaries for the admin list.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

use ContentGuard\Domain\Condition;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\Validation;

final class RulePresentation
{
    public static function conditionsSummary(Rule $rule): string
    {
        if ($rule->conditions === array()) {
            return 'Always applies';
        }

        $parts = array();
        foreach ($rule->conditions as $condition) {
            if ($condition instanceof Condition) {
                $parts[] = self::conditionSummary($condition);
            }
        }

        return implode(' AND ', $parts);
    }

    public static function validationsSummary(Rule $rule): string
    {
        $parts = array();
        foreach ($rule->validations as $validation) {
            if ($validation instanceof Validation) {
                $parts[] = self::validationSummary($validation);
            }
        }

        return $parts === array() ? 'None' : implode('; ', $parts);
    }

    private static function conditionSummary(Condition $condition): string
    {
        $field = $condition->field->label !== '' ? $condition->field->label : $condition->field->name;
        if ($field === '') {
            $field = $condition->field->key;
        }

        return match ($condition->operator) {
            'equals'       => $field . ' equals ' . self::scalar($condition->operand),
            'not_equals'   => $field . ' does not equal ' . self::scalar($condition->operand),
            'is_empty'     => $field . ' is empty',
            'is_not_empty' => $field . ' is not empty',
            default        => $field . ' ' . $condition->operator,
        };
    }

    private static function validationSummary(Validation $validation): string
    {
        $field = $validation->field->label !== '' ? $validation->field->label : $validation->field->name;
        if ($field === '') {
            $field = $validation->field->key;
        }

        return match ($validation->type) {
            'required'       => $field . ' is required',
            'min_length'     => $field . ' min length ' . (string) ($validation->params['min'] ?? ''),
            'max_length'     => $field . ' max length ' . (string) ($validation->params['max'] ?? ''),
            'allowed_values' => $field . ' allowed values',
            default          => $field . ' ' . $validation->type,
        };
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
