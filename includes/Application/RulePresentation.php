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
    /**
     * @param array<string, string> $fieldTypes Field key => ACF type.
     */
    public static function conditionsSummary(Rule $rule, array $fieldTypes = array()): string
    {
        if ($rule->conditions === array()) {
            return 'Always applies';
        }

        $parts = array();
        foreach ($rule->conditions as $condition) {
            if ($condition instanceof Condition) {
                $type    = $fieldTypes[$condition->field->key] ?? null;
                $parts[] = self::conditionSummary($condition, is_string($type) ? $type : null);
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

    private static function conditionSummary(Condition $condition, ?string $fieldType): string
    {
        $field = $condition->field->label !== '' ? $condition->field->label : $condition->field->name;
        if ($field === '') {
            $field = $condition->field->key;
        }

        $operand = FieldValuePresentation::label($condition->operand, $fieldType);
        if (
            FieldValuePresentation::isTrueFalse($fieldType)
            && FieldValuePresentation::isTrueFalseBit($condition->operand)
        ) {
            return match ($condition->operator) {
                'equals'     => $field . ' is ' . $operand,
                'not_equals' => $field . ' is not ' . $operand,
                default      => $field . ' ' . $condition->operator,
            };
        }

        return match ($condition->operator) {
            'equals'       => $field . ' equals ' . $operand,
            'not_equals'   => $field . ' does not equal ' . $operand,
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
}
