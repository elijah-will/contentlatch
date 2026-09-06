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
     * @return list<string>
     */
    public static function withOperand(): array
    {
        return array(
            'equals',
            'not_equals',
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

    public static function requiresOperand(string $operator): bool
    {
        return in_array($operator, self::withOperand(), true);
    }

    public static function isNumericComparison(string $operator): bool
    {
        return in_array($operator, self::numericComparison(), true);
    }

    public static function isNumericField(string $fieldType): bool
    {
        return $fieldType === 'number' || $fieldType === 'range';
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

        return array(
            'equals'       => 'is',
            'not_equals'   => 'is not',
            'is_empty'     => 'is empty',
            'is_not_empty' => 'is not empty',
        );
    }
}
