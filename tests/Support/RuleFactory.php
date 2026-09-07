<?php
/**
 * Test helpers for building rules.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Support;

use ContentGuard\Domain\Condition;
use ContentGuard\Domain\FieldRef;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Domain\Validation;

final class RuleFactory
{
    public static function field(string $key, string $name = '', string $label = ''): FieldRef
    {
        return new FieldRef($key, $name !== '' ? $name : $key, $label !== '' ? $label : $key);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function condition(array $overrides = array()): Condition
    {
        $field = $overrides['field'] ?? self::field('field_type', 'product_type', 'Product Type');

        if (is_array($field)) {
            $field = FieldRef::fromArray($field);
        }

        return new Condition(
            (string) ($overrides['id'] ?? 'c1'),
            $field,
            (string) ($overrides['operator'] ?? 'equals'),
            $overrides['operand'] ?? 'sauce',
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function validation(array $overrides = array()): Validation
    {
        $field = $overrides['field'] ?? self::field('field_ingredients', 'ingredients', 'Ingredients');

        if (is_array($field)) {
            $field = FieldRef::fromArray($field);
        }

        return new Validation(
            (string) ($overrides['id'] ?? 'v1'),
            $field,
            (string) ($overrides['type'] ?? 'required'),
            $overrides['params'] ?? array(),
            (string) ($overrides['message'] ?? ''),
            (string) ($overrides['quantifier'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function rule(array $overrides = array()): Rule
    {
        return new Rule(
            $overrides['id'] ?? 1,
            (string) ($overrides['name'] ?? 'Example rule'),
            (string) ($overrides['postType'] ?? 'product'),
            $overrides['status'] ?? RuleStatus::Active,
            $overrides['severity'] ?? RuleSeverity::Fail,
            $overrides['conditions'] ?? array(self::condition()),
            $overrides['validations'] ?? array(self::validation()),
            (int) ($overrides['schemaVersion'] ?? Rule::SCHEMA_VERSION),
            (string) ($overrides['conditionLogic'] ?? Rule::CONDITION_LOGIC_AND),
            $overrides['updatedAt'] ?? null,
            $overrides['createdAt'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function document(array $overrides = array()): array
    {
        return array_merge(self::rule()->toArray(), $overrides);
    }
}
