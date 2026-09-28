<?php
/**
 * Human-readable rule summaries for the admin list.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application;

defined('ABSPATH') || exit;

use ContentLatch\Domain\Condition;
use ContentLatch\Domain\Rule;
use ContentLatch\Domain\Validation;

final class RulePresentation
{
    /**
     * @param array<string, string> $fieldTypes Field key => ACF type.
     */
    public static function conditionsSummary(Rule $rule, array $fieldTypes = array()): string
    {
        if ($rule->conditions === array()) {
            return __('Always applies', 'contentlatch');
        }

        $parts = array();
        foreach ($rule->conditions as $condition) {
            if ($condition instanceof Condition) {
                $type    = $fieldTypes[$condition->field->resolutionId()] ?? $fieldTypes[$condition->field->key] ?? null;
                $parts[] = self::conditionSummary($condition, is_string($type) ? $type : null);
            }
        }

        /* translators: Joins multiple condition summary phrases. */
        return implode(__(' AND ', 'contentlatch'), $parts);
    }

    public static function validationsSummary(Rule $rule): string
    {
        $parts = array();
        foreach ($rule->validations as $validation) {
            if ($validation instanceof Validation) {
                $parts[] = self::validationSummary($validation);
            }
        }

        return $parts === array() ? __('None', 'contentlatch') : implode('; ', $parts);
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
                'equals'     => sprintf(__('%1$s is %2$s', 'contentlatch'), $field, $operand),
                /* translators: 1: Field label. 2: Yes/No value. */
                'not_equals' => sprintf(__('%1$s is not %2$s', 'contentlatch'), $field, $operand),
                default      => $field . ' ' . $condition->operator,
            };
        }

        return match ($condition->operator) {
            /* translators: 1: Field label. 2: Comparison value. */
            'equals'                => sprintf(__('%1$s equals %2$s', 'contentlatch'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'not_equals'            => sprintf(__('%1$s does not equal %2$s', 'contentlatch'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'contains'              => sprintf(__('%1$s contains %2$s', 'contentlatch'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'does_not_contain'      => sprintf(__('%1$s does not contain %2$s', 'contentlatch'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'greater_than'          => sprintf(__('%1$s is greater than %2$s', 'contentlatch'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'greater_than_or_equal' => sprintf(__('%1$s is at least %2$s', 'contentlatch'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'less_than'             => sprintf(__('%1$s is less than %2$s', 'contentlatch'), $field, $operand),
            /* translators: 1: Field label. 2: Comparison value. */
            'less_than_or_equal'    => sprintf(__('%1$s is at most %2$s', 'contentlatch'), $field, $operand),
            /* translators: %s: Field label. */
            'is_empty'              => sprintf(__('%s is empty', 'contentlatch'), $field),
            /* translators: %s: Field label. */
            'is_not_empty'          => sprintf(__('%s is not empty', 'contentlatch'), $field),
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
            'required'       => sprintf(__('%s is required', 'contentlatch'), $field),
            /* translators: 1: Field label. 2: Minimum length. */
            'min_length'     => sprintf(__('%1$s min length %2$s', 'contentlatch'), $field, (string) ($validation->params['min'] ?? '')),
            /* translators: 1: Field label. 2: Maximum length. */
            'max_length'     => sprintf(__('%1$s max length %2$s', 'contentlatch'), $field, (string) ($validation->params['max'] ?? '')),
            /* translators: %s: Field label. */
            'allowed_values' => sprintf(__('%s allowed values', 'contentlatch'), $field),
            default          => $field . ' ' . $validation->type,
        };
    }
}
