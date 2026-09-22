<?php
/**
 * Human-readable audit labels for the admin UI.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

defined('ABSPATH') || exit;

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

        return __('Deleted rule', 'contentguard');
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
        return $title !== '' ? $title : __('Content no longer available', 'contentguard');
    }

    public static function editContentLabel(): string
    {
        return __('Edit content', 'contentguard');
    }

    public static function editContentAria(string $title): string
    {
        $name = self::postTitle($title);

        /* translators: %s: Content title. */
        return sprintf(__('Edit content: %s', 'contentguard'), $name);
    }

    public static function filteredEmptyHeading(): string
    {
        return __('No matching findings', 'contentguard');
    }

    public static function filteredEmptyText(): string
    {
        return __('Try changing or clearing your filters.', 'contentguard');
    }

    public static function findingsHeading(): string
    {
        return __('Issues', 'contentguard');
    }

    public static function findingsRangeLabel(int $paged, int $pageSize, int $total): string
    {
        if ($total <= 0 || $pageSize <= 0) {
            return __('Showing 0 of 0 findings', 'contentguard');
        }

        $page = max(1, $paged);
        $from = (($page - 1) * $pageSize) + 1;
        $to   = min($page * $pageSize, $total);
        if ($from > $total) {
            $from = $total;
            $to   = $total;
        }

        /* translators: 1: First finding number. 2: Last finding number. 3: Total findings. */
        return sprintf(__('Showing %1$d–%2$d of %3$d findings', 'contentguard'), $from, $to, $total);
    }

    public static function paginationLabel(): string
    {
        return __('Findings pagination', 'contentguard');
    }

    public static function historyRangeLabel(int $paged, int $pageSize, int $total): string
    {
        if ($total <= 0 || $pageSize <= 0) {
            return __('Showing 0 of 0 audits', 'contentguard');
        }

        $page = max(1, $paged);
        $from = (($page - 1) * $pageSize) + 1;
        $to   = min($page * $pageSize, $total);
        if ($from > $total) {
            $from = $total;
            $to   = $total;
        }

        /* translators: 1: First audit number. 2: Last audit number. 3: Total audits. */
        return sprintf(__('Showing %1$d–%2$d of %3$d audits', 'contentguard'), $from, $to, $total);
    }

    public static function historyPaginationLabel(): string
    {
        return __('Audit history pagination', 'contentguard');
    }

    public static function issuesCountLabel(int $count): string
    {
        /* translators: %d: Number of issues. */
        return sprintf(_n('%d issue', '%d issues', $count, 'contentguard'), $count);
    }

    public static function blockingCountLabel(int $count): string
    {
        /* translators: %d: Number of blocking findings. */
        return sprintf(_n('%d Blocking', '%d Blocking', $count, 'contentguard'), $count);
    }

    public static function warningCountLabel(int $count): string
    {
        /* translators: %d: Number of warning findings. */
        return sprintf(_n('%d Warning', '%d Warning', $count, 'contentguard'), $count);
    }

    public static function goToFieldEditAria(string $fieldLabel): string
    {
        $field = $fieldLabel !== '' ? $fieldLabel : __('field', 'contentguard');

        /* translators: %s: Field label. */
        return sprintf(__('Edit content and go to field: %s', 'contentguard'), $field);
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
        return sprintf(
            /* translators: %d: Number of missing required fields. */
            _n('%d required field is missing', '%d required fields are missing', $count, 'contentguard'),
            $count
        );
    }

    public static function validationIssuesSummary(int $count): string
    {
        return sprintf(
            /* translators: %d: Number of validation issues. */
            _n('%d validation issue needs attention', '%d validation issues need attention', $count, 'contentguard'),
            $count
        );
    }

    public static function moreFieldsLabel(int $hidden): string
    {
        /* translators: %d: Number of additional fields. */
        return sprintf(_n('+ %d more', '+ %d more', $hidden, 'contentguard'), $hidden);
    }

    public static function isRequiredMessage(string $message): bool
    {
        return (bool) preg_match('/\bis required\.?$/i', trim($message));
    }

    public static function firstRunHeading(): string
    {
        return __('Ready to check your content', 'contentguard');
    }

    public static function firstRunText(): string
    {
        return __('Run an audit to see whether your existing content follows your active rules.', 'contentguard');
    }

    public static function doesNotModifyContent(): string
    {
        return __('ContentGuard only reports issues. It does not change your content.', 'contentguard');
    }

    public static function firstRunOutcome(): string
    {
        return __('When it finishes, you will see what passed and what needs attention.', 'contentguard');
    }

    public static function noActiveRulesHeading(): string
    {
        return __('No active rules', 'contentguard');
    }

    public static function noActiveRulesText(): string
    {
        return __('Activate at least one rule before running an audit. Previous completed results, if any, stay available below.', 'contentguard');
    }

    public static function runningHeading(): string
    {
        return __('Audit in progress', 'contentguard');
    }

    public static function progressLabel(int $scanned, int $total): string
    {
        if ($total > 0) {
            /* translators: 1: Number of content items checked. 2: Total content items. */
            return sprintf(__('%1$d of %2$d content items checked', 'contentguard'), $scanned, $total);
        }

        /* translators: %d: Number of content items checked. */
        return sprintf(__('%d content items checked', 'contentguard'), $scanned);
    }

    public static function completedHeading(): string
    {
        return __('Audit completed', 'contentguard');
    }

    public static function allClearHeading(): string
    {
        return __('All clear', 'contentguard');
    }

    public static function allClearText(int $scanned): string
    {
        return $scanned > 0
            ? __('No issues were found in this audit.', 'contentguard')
            : __('This audit completed with no eligible content and no issues.', 'contentguard');
    }

    public static function failedHeading(): string
    {
        return __('Audit failed', 'contentguard');
    }

    public static function failedFallbackMessage(): string
    {
        return __('The audit could not be completed. It was not used as the latest completed result.', 'contentguard');
    }

    public static function cancelledHeading(): string
    {
        return __('Audit cancelled', 'contentguard');
    }

    public static function cancelledText(): string
    {
        return __('The audit was stopped. Cancelled audits are not used as the latest completed result.', 'contentguard');
    }

    public static function cancelConfirmText(): string
    {
        return __('Stop this audit? It will not become the latest completed result.', 'contentguard');
    }

    public static function historicalNotice(): string
    {
        return __('This is not the current content health result.', 'contentguard');
    }

    public static function viewingResultsFrom(string $date): string
    {
        return $date !== ''
            /* translators: %s: Formatted audit date. */
            ? sprintf(__('Viewing results from %s', 'contentguard'), $date)
            : __('Viewing historical audit results', 'contentguard');
    }

    public static function backToLatestLabel(): string
    {
        return __('Back to latest audit', 'contentguard');
    }

    public static function currentAuditLabel(): string
    {
        return __('Current', 'contentguard');
    }

    public static function viewingAuditLabel(): string
    {
        return __('Viewing', 'contentguard');
    }

    public static function viewResultsLabel(): string
    {
        return __('View results', 'contentguard');
    }

    public static function historyHeading(): string
    {
        return __('Audit History', 'contentguard');
    }

    public static function showHistoryLabel(): string
    {
        return __('Show History', 'contentguard');
    }

    public static function hideHistoryLabel(): string
    {
        return __('Hide History', 'contentguard');
    }

    public static function historyEmptyText(): string
    {
        return __('No audit history yet.', 'contentguard');
    }

    public static function contentItemsCheckedLabel(int $scanned): string
    {
        return self::progressLabel($scanned, 0);
    }

    public static function historyContentOutcomeLabel(int $failed, int $warned): string
    {
        /* translators: 1: Count needing attention. 2: Count needing review. */
        return sprintf(__('%1$d need attention · %2$d need review', 'contentguard'), $failed, $warned);
    }

    public static function severityFilterLabel(): string
    {
        return __('Severity', 'contentguard');
    }

    public static function ruleFilterLabel(): string
    {
        return __('Rule', 'contentguard');
    }

    public static function contentTypeFilterLabel(): string
    {
        return __('Content type', 'contentguard');
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
