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
            return I18n::translate('Always applies');
        }

        $parts = array();
        foreach ($rule->conditions as $condition) {
            if ($condition instanceof Condition) {
                $type    = $fieldTypes[$condition->field->resolutionId()] ?? $fieldTypes[$condition->field->key] ?? null;
                $parts[] = self::conditionSummary($condition, is_string($type) ? $type : null);
            }
        }

        /* translators: Joins multiple condition summary phrases. */
        return implode(I18n::translate(' AND '), $parts);
    }

    public static function validationsSummary(Rule $rule): string
    {
        $parts = array();
        foreach ($rule->validations as $validation) {
            if ($validation instanceof Validation) {
                $parts[] = self::validationSummary($validation);
            }
        }

        return $parts === array() ? I18n::translate('None') : implode('; ', $parts);
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
                /* translators: 1: Field label. 2: Yes/No value. */
                'equals'     => I18n::sprintf(I18n::translate('%1$s is %2$s'), $field, $operand),
                /* translators: 1: Field label. 2: Yes/No value. */
                'not_equals' => I18n::sprintf(I18n::translate('%1$s is not %2$s'), $field, $operand),
                default      => $field . ' ' . $condition->operator,
            };
        }

        return match ($condition->operator) {
            /* translators: 1: Field label. 2: Comparison value. */
            'equals'                => I18n::sprintf(I18n::translate('%1$s equals %2$s'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'not_equals'            => I18n::sprintf(I18n::translate('%1$s does not equal %2$s'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'contains'              => I18n::sprintf(I18n::translate('%1$s contains %2$s'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'does_not_contain'      => I18n::sprintf(I18n::translate('%1$s does not contain %2$s'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'greater_than'          => I18n::sprintf(I18n::translate('%1$s is greater than %2$s'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'greater_than_or_equal' => I18n::sprintf(I18n::translate('%1$s is at least %2$s'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'less_than'             => I18n::sprintf(I18n::translate('%1$s is less than %2$s'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'less_than_or_equal'    => I18n::sprintf(I18n::translate('%1$s is at most %2$s'), $field, $operand),
            /* translators: %s: Field label. */
            'is_empty'              => I18n::sprintf(I18n::translate('%s is empty'), $field),
            /* translators: %s: Field label. */
            'is_not_empty'          => I18n::sprintf(I18n::translate('%s is not empty'), $field),
            default                 => $field . ' ' . $condition->operator,
        };
    }

    private static function validationSummary(Validation $validation): string
    {
        $field = $validation->field->label !== '' ? $validation->field->label : $validation->field->name;
        if ($field === '') {
            $field = $validation->field->key;
        }

        return match ($validation->type) {
            /* translators: %s: Field label. */
            'required'       => I18n::sprintf(I18n::translate('%s is required'), $field),
            /* translators: 1: Field label. 2: Minimum length. */
            'min_length'     => I18n::sprintf(I18n::translate('%1$s min length %2$s'), $field, (string) ($validation->params['min'] ?? '')),
            /* translators: 1: Field label. 2: Maximum length. */
            'max_length'     => I18n::sprintf(I18n::translate('%1$s max length %2$s'), $field, (string) ($validation->params['max'] ?? '')),
            /* translators: %s: Field label. */
            'allowed_values' => I18n::sprintf(I18n::translate('%s allowed values'), $field),
            default          => $field . ' ' . $validation->type,
        };
    }
}
