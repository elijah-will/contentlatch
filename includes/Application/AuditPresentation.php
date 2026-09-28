<?php
/**
 * Human-readable audit labels for the admin UI.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application;

defined('ABSPATH') || exit;

use ContentLatch\Domain\Condition;
use ContentLatch\Domain\Rule;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Domain\Validation;

final class AuditPresentation
{
    public const VISIBLE_FIELD_LIMIT = 5;

    public static function ruleName(?Rule $rule, int|string $ruleId): string
    {
        unset($ruleId);

        if ($rule !== null && $rule->name !== '') {
            return $rule->name;
        }

        return __('Deleted rule', 'contentlatch');
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
        return $title !== '' ? $title : __('Content no longer available', 'contentlatch');
    }

    public static function editContentLabel(): string
    {
        return __('Edit content', 'contentlatch');
    }

    public static function editContentAria(string $title): string
    {
        $name = self::postTitle($title);

        /* translators: %s: Content title. */
        return sprintf(__('Edit content: %s', 'contentlatch'), $name);
    }

    public static function filteredEmptyHeading(): string
    {
        return __('No matching findings', 'contentlatch');
    }

    public static function filteredEmptyText(): string
    {
        return __('Try changing or clearing your filters.', 'contentlatch');
    }

    public static function findingsHeading(): string
    {
        return __('Issues', 'contentlatch');
    }

    public static function findingsRangeLabel(int $paged, int $pageSize, int $total): string
    {
        if ($total <= 0 || $pageSize <= 0) {
            return __('Showing 0 of 0 findings', 'contentlatch');
        }

        $page = max(1, $paged);
        $from = (($page - 1) * $pageSize) + 1;
        $to   = min($page * $pageSize, $total);
        if ($from > $total) {
            $from = $total;
            $to   = $total;
        }

        /* translators: 1: First finding number. 2: Last finding number. 3: Total findings. */
        return sprintf(__('Showing %1$d–%2$d of %3$d findings', 'contentlatch'), $from, $to, $total);
    }

    public static function paginationLabel(): string
    {
        return __('Findings pagination', 'contentlatch');
    }

    public static function historyRangeLabel(int $paged, int $pageSize, int $total): string
    {
        if ($total <= 0 || $pageSize <= 0) {
            return __('Showing 0 of 0 audits', 'contentlatch');
        }

        $page = max(1, $paged);
        $from = (($page - 1) * $pageSize) + 1;
        $to   = min($page * $pageSize, $total);
        if ($from > $total) {
            $from = $total;
            $to   = $total;
        }

        /* translators: 1: First audit number. 2: Last audit number. 3: Total audits. */
        return sprintf(__('Showing %1$d–%2$d of %3$d audits', 'contentlatch'), $from, $to, $total);
    }

    public static function historyPaginationLabel(): string
    {
        return __('Audit history pagination', 'contentlatch');
    }

    public static function issuesCountLabel(int $count): string
    {
        /* translators: %d: Number of issues. */
        return sprintf(_n('%d issue', '%d issues', $count, 'contentlatch'), $count);
    }

    public static function blockingCountLabel(int $count): string
    {
        /* translators: %d: Number of blocking findings. */
        return sprintf(_n('%d Blocking', '%d Blocking', $count, 'contentlatch'), $count);
    }

    public static function warningCountLabel(int $count): string
    {
        /* translators: %d: Number of warning findings. */
        return sprintf(_n('%d Warning', '%d Warning', $count, 'contentlatch'), $count);
    }

    public static function goToFieldEditAria(string $fieldLabel): string
    {
        $field = $fieldLabel !== '' ? $fieldLabel : __('field', 'contentlatch');

        /* translators: %s: Field label. */
        return sprintf(__('Edit content and go to field: %s', 'contentlatch'), $field);
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
            _n('%d required field is missing', '%d required fields are missing', $count, 'contentlatch'),
            $count
        );
    }

    public static function validationIssuesSummary(int $count): string
    {
        return sprintf(
            /* translators: %d: Number of validation issues. */
            _n('%d validation issue needs attention', '%d validation issues need attention', $count, 'contentlatch'),
            $count
        );
    }

    public static function moreFieldsLabel(int $hidden): string
    {
        /* translators: %d: Number of additional fields. */
        return sprintf(_n('+ %d more', '+ %d more', $hidden, 'contentlatch'), $hidden);
    }

    public static function isRequiredMessage(string $message): bool
    {
        return (bool) preg_match('/\bis required\.?$/i', trim($message));
    }

    public static function firstRunHeading(): string
    {
        return __('Ready to check your content', 'contentlatch');
    }

    public static function firstRunText(): string
    {
        return __('Run an audit to see whether your existing content follows your active rules.', 'contentlatch');
    }

    public static function doesNotModifyContent(): string
    {
        return __('ContentLatch only reports issues. It does not change your content.', 'contentlatch');
    }

    public static function firstRunOutcome(): string
    {
        return __('When it finishes, you will see what passed and what needs attention.', 'contentlatch');
    }

    public static function noActiveRulesHeading(): string
    {
        return __('No active rules', 'contentlatch');
    }

    public static function noActiveRulesText(): string
    {
        return __('Activate at least one rule before running an audit. Previous completed results, if any, stay available below.', 'contentlatch');
    }

    public static function runningHeading(): string
    {
        return __('Audit in progress', 'contentlatch');
    }

    public static function progressLabel(int $scanned, int $total): string
    {
        if ($total > 0) {
            /* translators: 1: Number of content items checked. 2: Total content items. */
            return sprintf(__('%1$d of %2$d content items checked', 'contentlatch'), $scanned, $total);
        }

        /* translators: %d: Number of content items checked. */
        return sprintf(__('%d content items checked', 'contentlatch'), $scanned);
    }

    public static function completedHeading(): string
    {
        return __('Audit completed', 'contentlatch');
    }

    public static function allClearHeading(): string
    {
        return __('All clear', 'contentlatch');
    }

    public static function allClearText(int $scanned): string
    {
        return $scanned > 0
            ? __('No issues were found in this audit.', 'contentlatch')
            : __('This audit completed with no eligible content and no issues.', 'contentlatch');
    }

    public static function failedHeading(): string
    {
        return __('Audit failed', 'contentlatch');
    }

    public static function failedFallbackMessage(): string
    {
        return __('The audit could not be completed. It was not used as the latest completed result.', 'contentlatch');
    }

    public static function cancelledHeading(): string
    {
        return __('Audit cancelled', 'contentlatch');
    }

    public static function cancelledText(): string
    {
        return __('The audit was stopped. Cancelled audits are not used as the latest completed result.', 'contentlatch');
    }

    public static function cancelConfirmText(): string
    {
        return __('Stop this audit? It will not become the latest completed result.', 'contentlatch');
    }

    public static function historicalNotice(): string
    {
        return __('This is not the current content health result.', 'contentlatch');
    }

    public static function viewingResultsFrom(string $date): string
    {
        return $date !== ''
            /* translators: %s: Formatted audit date. */
            ? sprintf(__('Viewing results from %s', 'contentlatch'), $date)
            : __('Viewing historical audit results', 'contentlatch');
    }

    public static function backToLatestLabel(): string
    {
        return __('Back to latest audit', 'contentlatch');
    }

    public static function currentAuditLabel(): string
    {
        return __('Current', 'contentlatch');
    }

    public static function viewingAuditLabel(): string
    {
        return __('Viewing', 'contentlatch');
    }

    public static function viewResultsLabel(): string
    {
        return __('View results', 'contentlatch');
    }

    public static function historyHeading(): string
    {
        return __('Audit History', 'contentlatch');
    }

    public static function showHistoryLabel(): string
    {
        return __('Show History', 'contentlatch');
    }

    public static function hideHistoryLabel(): string
    {
        return __('Hide History', 'contentlatch');
    }

    public static function historyEmptyText(): string
    {
        return __('No audit history yet.', 'contentlatch');
    }

    public static function contentItemsCheckedLabel(int $scanned): string
    {
        return self::progressLabel($scanned, 0);
    }

    public static function historyContentOutcomeLabel(int $failed, int $warned): string
    {
        /* translators: 1: Count needing attention. 2: Count needing review. */
        return sprintf(__('%1$d need attention · %2$d need review', 'contentlatch'), $failed, $warned);
    }

    public static function severityFilterLabel(): string
    {
        return __('Severity', 'contentlatch');
    }

    public static function ruleFilterLabel(): string
    {
        return __('Rule', 'contentlatch');
    }

    public static function contentTypeFilterLabel(): string
    {
        return __('Content type', 'contentlatch');
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
