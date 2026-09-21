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

        return I18n::translate('Deleted rule');
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
        return $title !== '' ? $title : I18n::translate('Content no longer available');
    }

    public static function editContentLabel(): string
    {
        return I18n::translate('Edit content');
    }

    public static function editContentAria(string $title): string
    {
        $name = self::postTitle($title);

        /* translators: %s: Content title. */
        return I18n::sprintf(I18n::translate('Edit content: %s'), $name);
    }

    public static function filteredEmptyHeading(): string
    {
        return I18n::translate('No matching findings');
    }

    public static function filteredEmptyText(): string
    {
        return I18n::translate('Try changing or clearing your filters.');
    }

    public static function findingsHeading(): string
    {
        return I18n::translate('Issues');
    }

    public static function findingsRangeLabel(int $paged, int $pageSize, int $total): string
    {
        if ($total <= 0 || $pageSize <= 0) {
            return I18n::translate('Showing 0 of 0 findings');
        }

        $page = max(1, $paged);
        $from = (($page - 1) * $pageSize) + 1;
        $to   = min($page * $pageSize, $total);
        if ($from > $total) {
            $from = $total;
            $to   = $total;
        }

        /* translators: 1: First finding number. 2: Last finding number. 3: Total findings. */
        return I18n::sprintf(I18n::translate('Showing %1$d–%2$d of %3$d findings'), $from, $to, $total);
    }

    public static function paginationLabel(): string
    {
        return I18n::translate('Findings pagination');
    }

    public static function historyRangeLabel(int $paged, int $pageSize, int $total): string
    {
        if ($total <= 0 || $pageSize <= 0) {
            return I18n::translate('Showing 0 of 0 audits');
        }

        $page = max(1, $paged);
        $from = (($page - 1) * $pageSize) + 1;
        $to   = min($page * $pageSize, $total);
        if ($from > $total) {
            $from = $total;
            $to   = $total;
        }

        /* translators: 1: First audit number. 2: Last audit number. 3: Total audits. */
        return I18n::sprintf(I18n::translate('Showing %1$d–%2$d of %3$d audits'), $from, $to, $total);
    }

    public static function historyPaginationLabel(): string
    {
        return I18n::translate('Audit history pagination');
    }

    public static function issuesCountLabel(int $count): string
    {
        /* translators: %d: Number of issues. */
        return I18n::sprintf(I18n::translatePlural('%d issue', '%d issues', $count), $count);
    }

    public static function blockingCountLabel(int $count): string
    {
        /* translators: %d: Number of blocking findings. */
        return I18n::sprintf(I18n::translatePlural('%d Blocking', '%d Blocking', $count), $count);
    }

    public static function warningCountLabel(int $count): string
    {
        /* translators: %d: Number of warning findings. */
        return I18n::sprintf(I18n::translatePlural('%d Warning', '%d Warning', $count), $count);
    }

    public static function goToFieldEditAria(string $fieldLabel): string
    {
        $field = $fieldLabel !== '' ? $fieldLabel : I18n::translate('field');

        /* translators: %s: Field label. */
        return I18n::sprintf(I18n::translate('Edit content and go to field: %s'), $field);
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
        /* translators: %d: Number of missing required fields. */
        return I18n::sprintf(
            I18n::translatePlural('%d required field is missing', '%d required fields are missing', $count),
            $count
        );
    }

    public static function validationIssuesSummary(int $count): string
    {
        /* translators: %d: Number of validation issues. */
        return I18n::sprintf(
            I18n::translatePlural('%d validation issue needs attention', '%d validation issues need attention', $count),
            $count
        );
    }

    public static function moreFieldsLabel(int $hidden): string
    {
        /* translators: %d: Number of additional fields. */
        return I18n::sprintf(I18n::translatePlural('+ %d more', '+ %d more', $hidden), $hidden);
    }

    public static function isRequiredMessage(string $message): bool
    {
        return (bool) preg_match('/\bis required\.?$/i', trim($message));
    }

    public static function firstRunHeading(): string
    {
        return I18n::translate('Ready to check your content');
    }

    public static function firstRunText(): string
    {
        return I18n::translate('Run an audit to see whether your existing content follows your active rules.');
    }

    public static function doesNotModifyContent(): string
    {
        return I18n::translate('ContentGuard only reports issues. It does not change your content.');
    }

    public static function firstRunOutcome(): string
    {
        return I18n::translate('When it finishes, you will see what passed and what needs attention.');
    }

    public static function noActiveRulesHeading(): string
    {
        return I18n::translate('No active rules');
    }

    public static function noActiveRulesText(): string
    {
        return I18n::translate('Activate at least one rule before running an audit. Previous completed results, if any, stay available below.');
    }

    public static function runningHeading(): string
    {
        return I18n::translate('Audit in progress');
    }

    public static function progressLabel(int $scanned, int $total): string
    {
        if ($total > 0) {
            /* translators: 1: Number of content items checked. 2: Total content items. */
            return I18n::sprintf(I18n::translate('%1$d of %2$d content items checked'), $scanned, $total);
        }

        /* translators: %d: Number of content items checked. */
        return I18n::sprintf(I18n::translate('%d content items checked'), $scanned);
    }

    public static function completedHeading(): string
    {
        return I18n::translate('Audit completed');
    }

    public static function allClearHeading(): string
    {
        return I18n::translate('All clear');
    }

    public static function allClearText(int $scanned): string
    {
        return $scanned > 0
            ? I18n::translate('No issues were found in this audit.')
            : I18n::translate('This audit completed with no eligible content and no issues.');
    }

    public static function failedHeading(): string
    {
        return I18n::translate('Audit failed');
    }

    public static function failedFallbackMessage(): string
    {
        return I18n::translate('The audit could not be completed. It was not used as the latest completed result.');
    }

    public static function cancelledHeading(): string
    {
        return I18n::translate('Audit cancelled');
    }

    public static function cancelledText(): string
    {
        return I18n::translate('The audit was stopped. Cancelled audits are not used as the latest completed result.');
    }

    public static function cancelConfirmText(): string
    {
        return I18n::translate('Stop this audit? It will not become the latest completed result.');
    }

    public static function historicalNotice(): string
    {
        return I18n::translate('This is not the current content health result.');
    }

    public static function viewingResultsFrom(string $date): string
    {
        return $date !== ''
            /* translators: %s: Formatted audit date. */
            ? I18n::sprintf(I18n::translate('Viewing results from %s'), $date)
            : I18n::translate('Viewing historical audit results');
    }

    public static function backToLatestLabel(): string
    {
        return I18n::translate('Back to latest audit');
    }

    public static function currentAuditLabel(): string
    {
        return I18n::translate('Current');
    }

    public static function viewingAuditLabel(): string
    {
        return I18n::translate('Viewing');
    }

    public static function viewResultsLabel(): string
    {
        return I18n::translate('View results');
    }

    public static function historyHeading(): string
    {
        return I18n::translate('Audit History');
    }

    public static function showHistoryLabel(): string
    {
        return I18n::translate('Show History');
    }

    public static function hideHistoryLabel(): string
    {
        return I18n::translate('Hide History');
    }

    public static function historyEmptyText(): string
    {
        return I18n::translate('No audit history yet.');
    }

    public static function contentItemsCheckedLabel(int $scanned): string
    {
        return self::progressLabel($scanned, 0);
    }

    public static function historyContentOutcomeLabel(int $failed, int $warned): string
    {
        /* translators: 1: Count needing attention. 2: Count needing review. */
        return I18n::sprintf(I18n::translate('%1$d need attention · %2$d need review'), $failed, $warned);
    }

    public static function severityFilterLabel(): string
    {
        return I18n::translate('Severity');
    }

    public static function ruleFilterLabel(): string
    {
        return I18n::translate('Rule');
    }

    public static function contentTypeFilterLabel(): string
    {
        return I18n::translate('Content type');
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
