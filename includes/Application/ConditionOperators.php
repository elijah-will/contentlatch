<?php
/**
 * Condition operator catalog for the builder and factory.
 *
 * Stored operator IDs stay unchanged. Labels are presentation only.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

final class ConditionOperators
{
    /**
     * Catalog types whose resolved values are string-like enough for
     * contains / does not contain.
     *
     * @var list<string>
     */
    private const STRING_CONTENT_TYPES = array(
        'text',
        'textarea',
        'email',
        'url',
        'password',
        'wysiwyg',
        'select',
        'radio',
        'button_group',
    );

    /**
     * @return list<string>
     */
    public static function withOperand(): array
    {
        return array(
            'equals',
            'not_equals',
            'contains',
            'does_not_contain',
            'greater_than',
            'greater_than_or_equal',
            'less_than',
            'less_than_or_equal',
        );
    }

    /**
     * @return list<string>
     */
    public static function numericComparison(): array
    {
        return array(
            'greater_than',
            'greater_than_or_equal',
            'less_than',
            'less_than_or_equal',
        );
    }

    /**
     * @return list<string>
     */
    public static function stringContains(): array
    {
        return array(
            'contains',
            'does_not_contain',
        );
    }

    public static function requiresOperand(string $operator): bool
    {
        return in_array($operator, self::withOperand(), true);
    }

    public static function isNumericComparison(string $operator): bool
    {
        return in_array($operator, self::numericComparison(), true);
    }

    public static function isStringContains(string $operator): bool
    {
        return in_array($operator, self::stringContains(), true);
    }

    public static function isNumericField(string $fieldType): bool
    {
        return $fieldType === 'number' || $fieldType === 'range';
    }

    public static function isStringContentField(string $fieldType): bool
    {
        return in_array($fieldType, self::STRING_CONTENT_TYPES, true);
    }

    /**
     * @return array<string, string> id => label
     */
    public static function labelsForFieldType(string $fieldType): array
    {
        if (self::isNumericField($fieldType)) {
            return array(
                'equals'                => 'is equal to',
                'not_equals'            => 'is not equal to',
                'greater_than'          => 'is greater than',
                'greater_than_or_equal' => 'is at least',
                'less_than'             => 'is less than',
                'less_than_or_equal'    => 'is at most',
                'is_empty'              => 'is empty',
                'is_not_empty'          => 'is not empty',
            );
        }

        $labels = array(
            'equals'       => 'is',
            'not_equals'   => 'is not',
            'is_empty'     => 'is empty',
            'is_not_empty' => 'is not empty',
        );

        if ($fieldType !== '' && !self::isStringContentField($fieldType)) {
            return $labels;
        }

        return array(
            'equals'           => 'is',
            'not_equals'       => 'is not',
            'contains'         => 'contains',
            'does_not_contain' => 'does not contain',
            'is_empty'         => 'is empty',
            'is_not_empty'     => 'is not empty',
        );
    }

    /**
     * Builder operator maps keyed by catalog field type.
     * Default omits contains so unknown/non-string types stay conservative.
     *
     * @return array<string, array<string, string>>
     */
    public static function labelsByFieldType(): array
    {
        $types = array_merge(
            self::STRING_CONTENT_TYPES,
            array(
                'number',
                'range',
                'true_false',
                'date_picker',
                'date_time_picker',
                'color_picker',
            )
        );

        $map = array(
            'default' => self::labelsForFieldType('text'),
        );

        foreach ($types as $type) {
            $map[$type] = self::labelsForFieldType($type);
        }

        return $map;
    }
}
