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
    public const VISIBLE_FIELD_LIMIT = 5;

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
            if (
                $validation instanceof Validation
                && ($validation->field->key === $fieldKey || $validation->field->resolutionId() === $fieldKey)
            ) {
                return self::fieldRefLabel($validation->field->label, $validation->field->name, $fieldKey);
            }
        }

        foreach ($rule->conditions as $condition) {
            if (
                $condition instanceof Condition
                && ($condition->field->key === $fieldKey || $condition->field->resolutionId() === $fieldKey)
            ) {
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

    public static function findingsRangeLabel(int $paged, int $pageSize, int $total): string
    {
        if ($total <= 0 || $pageSize <= 0) {
            return 'Showing 0 of 0 findings';
        }

        $page = max(1, $paged);
        $from = (($page - 1) * $pageSize) + 1;
        $to   = min($page * $pageSize, $total);
        if ($from > $total) {
            $from = $total;
            $to   = $total;
        }

        return sprintf('Showing %d–%d of %d findings', $from, $to, $total);
    }

    public static function paginationLabel(): string
    {
        return 'Findings pagination';
    }

    public static function historyRangeLabel(int $paged, int $pageSize, int $total): string
    {
        if ($total <= 0 || $pageSize <= 0) {
            return 'Showing 0 of 0 audits';
        }

        $page = max(1, $paged);
        $from = (($page - 1) * $pageSize) + 1;
        $to   = min($page * $pageSize, $total);
        if ($from > $total) {
            $from = $total;
            $to   = $total;
        }

        return sprintf('Showing %d–%d of %d audits', $from, $to, $total);
    }

    public static function historyPaginationLabel(): string
    {
        return 'Audit history pagination';
    }

    public static function issuesCountLabel(int $count): string
    {
        return $count === 1 ? '1 issue' : sprintf('%d issues', $count);
    }

    public static function blockingCountLabel(int $count): string
    {
        return $count === 1 ? '1 Blocking' : sprintf('%d Blocking', $count);
    }

    public static function warningCountLabel(int $count): string
    {
        return $count === 1 ? '1 Warning' : sprintf('%d Warning', $count);
    }

    public static function goToFieldEditAria(string $fieldLabel): string
    {
        $field = $fieldLabel !== '' ? $fieldLabel : 'field';

        return sprintf('Edit content and go to field: %s', $field);
    }

    public static function displayFieldLabel(string $label, string $fieldKey = ''): string
    {
        $label = trim($label);
        if ($label === '' || $label === $fieldKey || EditorFieldNavigation::isSafeFieldKey($label)) {
            return '';
        }

        return $label;
    }

    /**
     * @param list<string> $messages
     */
    public static function groupSummary(array $messages, int $issueCount): string
    {
        $messages = array_values(array_filter(array_map('strval', $messages), static fn (string $message): bool => $message !== ''));
        if ($issueCount <= 1) {
            return $messages[0] ?? '';
        }

        $unique = array_values(array_unique($messages));
        $allRequired = $unique !== array() && array_reduce(
            $unique,
            static fn (bool $carry, string $message): bool => $carry && self::isRequiredMessage($message),
            true
        );
        if ($allRequired) {
            return self::requiredFieldsSummary($issueCount);
        }

        if (count($unique) === 1) {
            return $unique[0];
        }

        return self::validationIssuesSummary($issueCount);
    }

    public static function requiredFieldsSummary(int $count): string
    {
        return $count === 1
            ? '1 required field is missing'
            : sprintf('%d required fields are missing', $count);
    }

    public static function validationIssuesSummary(int $count): string
    {
        return $count === 1
            ? '1 validation issue needs attention'
            : sprintf('%d validation issues need attention', $count);
    }

    public static function moreFieldsLabel(int $hidden): string
    {
        return $hidden === 1 ? '+ 1 more' : sprintf('+ %d more', $hidden);
    }

    public static function isRequiredMessage(string $message): bool
    {
        return (bool) preg_match('/\bis required\.?$/i', trim($message));
    }

    public static function firstRunHeading(): string
    {
        return 'Ready to check your content';
    }

    public static function firstRunText(): string
    {
        return 'Run an audit to see whether your existing content follows your active rules.';
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
            ? 'No issues were found in this audit.'
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

    public static function viewingResultsFrom(string $date): string
    {
        return $date !== ''
            ? sprintf('Viewing results from %s', $date)
            : 'Viewing historical audit results';
    }

    public static function backToLatestLabel(): string
    {
        return 'Back to latest audit';
    }

    public static function currentAuditLabel(): string
    {
        return 'Current';
    }

    public static function viewingAuditLabel(): string
    {
        return 'Viewing';
    }

    public static function viewResultsLabel(): string
    {
        return 'View results';
    }

    public static function historyHeading(): string
    {
        return 'Audit History';
    }

    public static function historyEmptyText(): string
    {
        return 'No audit history yet.';
    }

    public static function contentItemsCheckedLabel(int $scanned): string
    {
        return self::progressLabel($scanned, 0);
    }

    public static function historyContentOutcomeLabel(int $failed, int $warned): string
    {
        return sprintf('%d need attention · %d need review', $failed, $warned);
    }

    public static function severityFilterLabel(): string
    {
        return 'Severity';
    }

    public static function ruleFilterLabel(): string
    {
        return 'Rule';
    }

    public static function contentTypeFilterLabel(): string
    {
        return 'Content type';
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
