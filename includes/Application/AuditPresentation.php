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

    public static function editContentLabel(): string
    {
        return 'Edit content';
    }

    public static function editContentAria(string $title): string
    {
        $name = self::postTitle($title);

        return sprintf('Edit content: %s', $name);
    }

    public static function filteredEmptyHeading(): string
    {
        return 'No matching findings';
    }

    public static function filteredEmptyText(): string
    {
        return 'Try changing or clearing your filters.';
    }

    public static function findingsHeading(): string
    {
        return 'Issues';
    }

    public static function firstRunHeading(): string
    {
        return 'Ready to check your content';
    }

    public static function firstRunText(): string
    {
        return 'Run an audit to check your published and private content against your active ContentGuard rules.';
    }

    public static function doesNotModifyContent(): string
    {
        return 'ContentGuard only reports issues. It does not change your content.';
    }

    public static function firstRunOutcome(): string
    {
        return 'When it finishes, you will see what passed and what needs attention.';
    }

    public static function noActiveRulesHeading(): string
    {
        return 'No active rules';
    }

    public static function noActiveRulesText(): string
    {
        return 'Activate at least one rule before running an audit. Previous completed results, if any, stay available below.';
    }

    public static function runningHeading(): string
    {
        return 'Audit in progress';
    }

    public static function progressLabel(int $scanned, int $total): string
    {
        if ($total > 0) {
            return sprintf('%d of %d content items checked', $scanned, $total);
        }

        return sprintf('%d content items checked', $scanned);
    }

    public static function completedHeading(): string
    {
        return 'Audit completed';
    }

    public static function allClearHeading(): string
    {
        return 'All clear';
    }

    public static function allClearText(int $scanned): string
    {
        return $scanned > 0
            ? 'No active rule violations were found in the audited content.'
            : 'This audit completed with no eligible content and no issues.';
    }

    public static function failedHeading(): string
    {
        return 'Audit failed';
    }

    public static function failedFallbackMessage(): string
    {
        return 'The audit could not be completed. It was not used as the latest completed result.';
    }

    public static function cancelledHeading(): string
    {
        return 'Audit cancelled';
    }

    public static function cancelledText(): string
    {
        return 'The audit was stopped. Cancelled audits are not used as the latest completed result.';
    }

    public static function cancelConfirmText(): string
    {
        return 'Stop this audit? It will not become the latest completed result.';
    }

    public static function historicalNotice(): string
    {
        return 'This is not the current content health result.';
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
