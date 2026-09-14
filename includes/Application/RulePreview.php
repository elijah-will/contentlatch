<?php
/**
 * Human-readable Rule Builder preview. Presentation only.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

final class RulePreview
{
    public static function needThenMessage(): string
    {
        return I18n::translate('Add a THEN requirement to preview this rule.');
    }

    public static function needWhenOrThenMessage(): string
    {
        return I18n::translate('Add a WHEN condition or THEN requirement to preview this rule.');
    }

    public static function incompleteWhenMessage(): string
    {
        return I18n::translate('Finish the WHEN condition to preview this rule.');
    }

    public static function incompleteThenMessage(): string
    {
        return I18n::translate('Finish the THEN requirement to preview this rule.');
    }

    public static function conditionOnlyBlockingMessage(): string
    {
        return I18n::translate('this rule blocks publishing');
    }

    public static function conditionOnlyWarningMessage(): string
    {
        return I18n::translate('this rule reports a warning');
    }

    /**
     * @param list<array{field_key?: string, operator?: string, operand?: string}> $conditions
     * @param list<array{field_key?: string, type?: string, min?: string, max?: string, values?: string}> $validations
     * @param array<string, array{label?: string, type?: string}> $fieldMeta
     */
    public static function fromEditor(
        array $conditions,
        array $validations,
        array $fieldMeta = array(),
        string $severity = 'fail',
    ): string {
        $when = self::whenState($conditions, $fieldMeta);
        $then = self::thenState($validations, $fieldMeta);

        if ($when['state'] === 'incomplete') {
            return self::incompleteWhenMessage();
        }
        if ($then['state'] === 'incomplete') {
            return self::incompleteThenMessage();
        }
        if ($then['state'] === 'empty') {
            if ($when['text'] === '') {
                return self::needWhenOrThenMessage();
            }

            $consequence = $severity === 'warning'
                ? self::conditionOnlyWarningMessage()
                : self::conditionOnlyBlockingMessage();

            /* translators: 1: When conditions phrase. 2: Consequence phrase (blocks publishing / reports a warning). */
            return I18n::sprintf(I18n::translate('When %1$s, %2$s.'), $when['text'], $consequence);
        }

        if ($when['text'] === '') {
            /* translators: %s: Then requirement phrase. */
            return I18n::sprintf(I18n::translate('%s.'), $then['text']);
        }

        /* translators: 1: When conditions phrase. 2: Then requirement phrase. */
        return I18n::sprintf(I18n::translate('When %1$s, %2$s.'), $when['text'], $then['text']);
    }

    /**
     * @param list<array{field_key?: string, operator?: string, operand?: string}> $conditions
     * @param array<string, array{label?: string, type?: string}> $fieldMeta
     * @return array{state: string, text: string}
     */
    private static function whenState(array $conditions, array $fieldMeta): array
    {
        $parts = array();

        foreach ($conditions as $row) {
            if (!is_array($row)) {
                continue;
            }

            $fieldKey = trim((string) ($row['field_key'] ?? ''));
            $operator = trim((string) ($row['operator'] ?? ''));
            $operand  = $row['operand'] ?? '';
            $started  = $fieldKey !== '' || $operator !== '' || self::scalar($operand) !== '';

            if (!$started) {
                continue;
            }

            if ($fieldKey === '' || $operator === '') {
                return array('state' => 'incomplete', 'text' => '');
            }

            $needsValue = ConditionOperators::requiresOperand($operator);
            if ($needsValue && self::scalar($operand) === '') {
                return array('state' => 'incomplete', 'text' => '');
            }

            $meta  = $fieldMeta[$fieldKey] ?? array();
            $parts[] = self::conditionPhrase(
                self::fieldLabel($fieldKey, $meta),
                $operator,
                $operand,
                is_string($meta['type'] ?? null) ? $meta['type'] : null
            );
        }

        return array(
            'state' => 'ready',
            /* translators: Joins multiple When condition phrases. */
            'text'  => implode(I18n::translate(' and '), $parts),
        );
    }

    /**
     * @param list<array{field_key?: string, type?: string, min?: string, max?: string, values?: string}> $validations
     * @param array<string, array{label?: string, type?: string}> $fieldMeta
     * @return array{state: string, text: string}
     */
    private static function thenState(array $validations, array $fieldMeta): array
    {
        $parts = array();

        foreach ($validations as $row) {
            if (!is_array($row)) {
                continue;
            }

            $fieldKey = trim((string) ($row['field_key'] ?? ''));
            $type     = trim((string) ($row['type'] ?? ''));
            $min      = self::scalar($row['min'] ?? '');
            $max      = self::scalar($row['max'] ?? '');
            $values   = self::scalar($row['values'] ?? '');
            $started  = $fieldKey !== '' || $min !== '' || $max !== '' || $values !== '';

            if (!$started && $type === '') {
                continue;
            }

            if ($fieldKey === '' || $type === '') {
                return array('state' => $parts === array() ? 'empty' : 'incomplete', 'text' => '');
            }

            if ($type === 'min_length' && $min === '') {
                return array('state' => 'incomplete', 'text' => '');
            }
            if ($type === 'max_length' && $max === '') {
                return array('state' => 'incomplete', 'text' => '');
            }
            if ($type === 'allowed_values' && trim(str_replace(' ', '', $values)) === '') {
                return array('state' => 'incomplete', 'text' => '');
            }

            $meta    = $fieldMeta[$fieldKey] ?? array();
            $parts[] = self::validationPhrase(self::fieldLabel($fieldKey, $meta), $type, $min, $max, $values);
        }

        if ($parts === array()) {
            return array('state' => 'empty', 'text' => '');
        }

        return array(
            'state' => 'ready',
            /* translators: Joins multiple Then requirement phrases. */
            'text'  => implode(I18n::translate(' and '), $parts),
        );
    }

    /**
     * @param array{label?: string, type?: string} $meta
     */
    private static function fieldLabel(string $key, array $meta): string
    {
        $label = trim((string) ($meta['label'] ?? ''));

        return $label !== '' ? $label : $key;
    }

    private static function conditionPhrase(string $field, string $operator, mixed $operand, ?string $fieldType): string
    {
        $value = FieldValuePresentation::label($operand, $fieldType);

        return match ($operator) {
            /* translators: 1: Field label. 2: Comparison value. */
            'equals'                => I18n::sprintf(I18n::translate('%1$s is %2$s'), $field, $value),
            /* translators: 1: Field label. 2: Comparison value. */
            'not_equals'            => I18n::sprintf(I18n::translate('%1$s is not %2$s'), $field, $value),
            /* translators: 1: Field label. 2: Comparison value. */
            'contains'              => I18n::sprintf(I18n::translate('%1$s contains %2$s'), $field, $value),
            /* translators: 1: Field label. 2: Comparison value. */
            'does_not_contain'      => I18n::sprintf(I18n::translate('%1$s does not contain %2$s'), $field, $value),
            /* translators: 1: Field label. 2: Comparison value. */
            'greater_than'          => I18n::sprintf(I18n::translate('%1$s is greater than %2$s'), $field, $value),
            /* translators: 1: Field label. 2: Comparison value. */
            'greater_than_or_equal' => I18n::sprintf(I18n::translate('%1$s is at least %2$s'), $field, $value),
            /* translators: 1: Field label. 2: Comparison value. */
            'less_than'             => I18n::sprintf(I18n::translate('%1$s is less than %2$s'), $field, $value),
            /* translators: 1: Field label. 2: Comparison value. */
            'less_than_or_equal'    => I18n::sprintf(I18n::translate('%1$s is at most %2$s'), $field, $value),
            /* translators: %s: Field label. */
            'is_empty'              => I18n::sprintf(I18n::translate('%s is empty'), $field),
            /* translators: %s: Field label. */
            'is_not_empty'          => I18n::sprintf(I18n::translate('%s is not empty'), $field),
            default                 => $field . ' ' . $operator,
        };
    }

    private static function validationPhrase(string $field, string $type, string $min, string $max, string $values): string
    {
        return match ($type) {
            /* translators: %s: Field label. */
            'required'       => I18n::sprintf(I18n::translate('%s is required'), $field),
            /* translators: 1: Field label. 2: Minimum character count. */
            'min_length'     => I18n::sprintf(I18n::translate('%1$s must be at least %2$s characters'), $field, $min),
            /* translators: 1: Field label. 2: Maximum character count. */
            'max_length'     => I18n::sprintf(I18n::translate('%1$s must be at most %2$s characters'), $field, $max),
            /* translators: 1: Field label. 2: Allowed values list. */
            'allowed_values' => I18n::sprintf(I18n::translate('%1$s must be one of: %2$s'), $field, $values),
            default          => $field . ' ' . $type,
        };
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
