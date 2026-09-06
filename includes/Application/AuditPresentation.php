<?php
/**
 * Human-readable audit labels for the admin UI.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

use ContentGuard\Domain\Condition;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Domain\Validation;

final class AuditPresentation
{
    public static function ruleName(?Rule $rule, int|string $ruleId): string
    {
        unset($ruleId);

        if ($rule !== null && $rule->name !== '') {
            return $rule->name;
        }

        return 'Deleted rule';
    }

    public static function fieldLabel(?Rule $rule, string $fieldKey): string
    {
        if ($rule === null) {
            return $fieldKey;
        }

        foreach ($rule->validations as $validation) {
            if ($validation instanceof Validation && $validation->field->key === $fieldKey) {
                return self::fieldRefLabel($validation->field->label, $validation->field->name, $fieldKey);
            }
        }

        foreach ($rule->conditions as $condition) {
            if ($condition instanceof Condition && $condition->field->key === $fieldKey) {
                return self::fieldRefLabel($condition->field->label, $condition->field->name, $fieldKey);
            }
        }

        return $fieldKey;
    }

    public static function postTitle(string $title): string
    {
        return $title !== '' ? $title : 'Content no longer available';
    }

    public static function severityLabel(RuleSeverity $severity): string
    {
        return StatusPresentation::label(StatusPresentation::fromSeverity($severity));
    }

    private static function fieldRefLabel(string $label, string $name, string $key): string
    {
        if ($label !== '') {
            return $label;
        }

        if ($name !== '') {
            return $name;
        }

        return $key;
    }
}
