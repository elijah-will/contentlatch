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
        return 'Add a THEN requirement to preview this rule.';
    }

    public static function incompleteWhenMessage(): string
    {
        return 'Finish the WHEN condition to preview this rule.';
    }

    public static function incompleteThenMessage(): string
    {
        return 'Finish the THEN requirement to preview this rule.';
    }

    /**
     * @param list<array{field_key?: string, operator?: string, operand?: string}> $conditions
     * @param list<array{field_key?: string, type?: string, min?: string, max?: string, values?: string}> $validations
     * @param array<string, array{label?: string, type?: string}> $fieldMeta
     */
    public static function fromEditor(array $conditions, array $validations, array $fieldMeta = array()): string
    {
        $when = self::whenState($conditions, $fieldMeta);
        $then = self::thenState($validations, $fieldMeta);

        if ($when['state'] === 'incomplete') {
            return self::incompleteWhenMessage();
        }
        if ($then['state'] === 'empty') {
            return self::needThenMessage();
        }
        if ($then['state'] === 'incomplete') {
            return self::incompleteThenMessage();
        }

        if ($when['text'] === '') {
            return $then['text'] . '.';
        }

        return 'When ' . $when['text'] . ', ' . $then['text'] . '.';
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

            $needsValue = in_array($operator, array('equals', 'not_equals'), true);
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
            'text'  => implode(' and ', $parts),
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
            'text'  => implode(' and ', $parts),
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
            'equals'       => $field . ' is ' . $value,
            'not_equals'   => $field . ' is not ' . $value,
            'is_empty'     => $field . ' is empty',
            'is_not_empty' => $field . ' is not empty',
            default        => $field . ' ' . $operator,
        };
    }

    private static function validationPhrase(string $field, string $type, string $min, string $max, string $values): string
    {
        return match ($type) {
            'required'       => $field . ' is required',
            'min_length'     => $field . ' must be at least ' . $min . ' characters',
            'max_length'     => $field . ' must be at most ' . $max . ' characters',
            'allowed_values' => $field . ' must be one of: ' . $values,
            default          => $field . ' ' . $type,
        };
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
