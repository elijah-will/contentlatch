<?php
/**
 * Maps persisted audit findings into editor-notice issues.
 *
 * Blocking findings only. Warnings stay on the live editor warning path.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

use ContentGuard\Application\Audit\AuditFinding;
use ContentGuard\Domain\ContentEvaluation;
use ContentGuard\Domain\EvaluationResult;
use ContentGuard\Domain\RuleSeverity;

final class EditorAuditIssues
{
    /**
     * @param AuditFinding[] $findings
     * @param array<string, string> $fieldLabels
     * @return list<array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>
     */
    public static function fromFindings(array $findings, int $postId, array $fieldLabels = array()): array
    {
        if ($postId <= 0) {
            return array();
        }

        $items = array();
        $seen  = array();

        foreach ($findings as $finding) {
            if (!$finding instanceof AuditFinding) {
                continue;
            }

            if ($finding->postId !== $postId || $finding->severity !== RuleSeverity::Fail) {
                continue;
            }

            $item = self::fromFinding($finding, $fieldLabels);
            $id   = $item['message'] . "\0" . $item['fieldKey'] . "\0" . $item['label'];
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $items[]   = $item;
        }

        return $items;
    }

    /**
     * Current blocking issues from a live evaluation. Warnings are ignored.
     *
     * @return list<array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>
     */
    public static function fromEvaluation(?ContentEvaluation $evaluation, int $postId): array
    {
        if ($evaluation === null || $postId <= 0) {
            return array();
        }

        $items = array();
        $seen  = array();

        foreach ($evaluation->results as $result) {
            if (!$result instanceof EvaluationResult || !$result->isFailed()) {
                continue;
            }

            if ($result->postId !== null && $result->postId !== $postId) {
                continue;
            }

            $item = self::fromResult($result);
            $id   = $item['message'] . "\0" . $item['fieldKey'] . "\0" . $item['label'];
            if (isset($seen[$id])) {
                $items[$seen[$id]] = EditorFieldNavigation::mergeRowTargets($items[$seen[$id]], $item);
                continue;
            }

            $seen[$id] = count($items);
            $items[]   = $item;
        }

        return $items;
    }

    /**
     * @return array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}
     */
    public static function fromResult(EvaluationResult $result): array
    {
        $message = trim($result->message);
        if ($message === '') {
            $message = 'This field is required.';
        }

        $fieldKey = EditorFieldNavigation::navigableFieldKey($result->fieldId);
        $label    = trim((string) ($result->context['field_label'] ?? ''));

        return EditorFieldNavigation::withEvaluationRowTargets(
            array(
                'message'  => $message,
                'label'    => self::humanLabel($label, $fieldKey !== '' ? $fieldKey : (string) $result->fieldId),
                'fieldKey' => $fieldKey,
            ),
            $result->context
        );
    }

    /**
     * Keep the Audit-arrival notice scoped to fields that were in the run.
     *
     * @param list<array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}> $issues
     * @param list<string> $fieldKeys
     * @return list<array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>
     */
    public static function scopedToFieldKeys(array $issues, array $fieldKeys): array
    {
        if ($fieldKeys === array()) {
            return array();
        }

        $allowed = array();
        foreach ($fieldKeys as $key) {
            $safe = EditorFieldNavigation::navigableFieldKey($key);
            if ($safe !== '') {
                $allowed[$safe] = true;
            }
        }

        if ($allowed === array()) {
            return array();
        }

        $scoped = array();
        foreach ($issues as $issue) {
            $key = EditorFieldNavigation::navigableFieldKey($issue['fieldKey'] ?? null);
            if ($key !== '' && isset($allowed[$key])) {
                $scoped[] = $issue;
            }
        }

        return $scoped;
    }

    /**
     * @param list<array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}> $issues
     * @return array{issues: list<array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>, html: string, text: string}
     */
    public static function payload(array $issues): array
    {
        return array(
            'issues' => $issues,
            'html'   => self::noticeHtml($issues),
            'text'   => self::noticeText($issues),
        );
    }

    /**
     * @param array<string, string> $fieldLabels
     * @return array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}
     */
    public static function fromFinding(AuditFinding $finding, array $fieldLabels = array()): array
    {
        $message = trim($finding->message);
        if ($message === '') {
            $message = 'This field is required.';
        }

        $rawLabel = $fieldLabels[(string) $finding->ruleId . ':' . $finding->fieldKey]
            ?? $fieldLabels[$finding->fieldKey]
            ?? '';

        return EditorFieldNavigation::withSnapshotRowTargets(
            array(
                'message'  => $message,
                'label'    => self::humanLabel((string) $rawLabel, $finding->fieldKey),
                'fieldKey' => EditorFieldNavigation::navigableFieldKey($finding->fieldKey),
            ),
            $message
        );
    }

    /**
     * @param array{message?: string, label?: string, fieldKey?: string} $issue
     */
    public static function isClickable(array $issue): bool
    {
        return trim((string) ($issue['label'] ?? '')) !== ''
            && EditorFieldNavigation::navigableFieldKey($issue['fieldKey'] ?? null) !== '';
    }

    /**
     * @param list<array{message?: string, label?: string, fieldKey?: string}> $issues
     */
    public static function noticeText(array $issues): string
    {
        if ($issues === array()) {
            return '';
        }

        $lines = array('ContentGuard');
        $count = count($issues);
        if ($count > 1) {
            $lines[] = sprintf('%d blocking issues', $count);
        }

        foreach ($issues as $issue) {
            $line = self::issueText($issue);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{message?: string, label?: string, fieldKey?: string} $issue
     */
    public static function issueText(array $issue): string
    {
        $label   = trim((string) ($issue['label'] ?? ''));
        $message = trim((string) ($issue['message'] ?? ''));

        if ($label !== '' && $message !== '') {
            return $label . ' — ' . $message;
        }

        return $message !== '' ? $message : $label;
    }

    /**
     * @param list<array{message?: string, label?: string, fieldKey?: string}> $issues
     */
    public static function noticeHtml(array $issues): string
    {
        if ($issues === array()) {
            return '';
        }

        $html  = '<div class="contentguard-audit-blockers">';
        $html .= '<p class="contentguard-audit-blockers__title">' . self::escapeHtml('ContentGuard') . '</p>';
        if (count($issues) > 1) {
            $html .= '<p class="contentguard-audit-blockers__count">'
                . self::escapeHtml(sprintf('%d blocking issues', count($issues)))
                . '</p>';
        }

        $html .= '<ul class="contentguard-audit-blockers__list">';
        foreach ($issues as $issue) {
            $html .= '<li>' . self::issueHtml($issue) . '</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    /**
     * @param array{message?: string, label?: string, fieldKey?: string} $issue
     */
    public static function issueHtml(array $issue): string
    {
        $label    = trim((string) ($issue['label'] ?? ''));
        $message  = trim((string) ($issue['message'] ?? ''));
        $fieldKey = EditorFieldNavigation::navigableFieldKey($issue['fieldKey'] ?? null);
        $layout   = EditorFieldNavigation::layoutFromItem($issue);
        $rows     = EditorFieldNavigation::affectedRowsFromItem($issue);
        $text     = self::issueText($issue);

        if ($fieldKey === '' || $label === '') {
            return self::escapeHtml($text);
        }

        $primaryRow = EditorFieldNavigation::primaryDisplayRow($rows);
        $aria       = count($rows) === 1
            ? EditorFieldNavigation::goToLayoutRowAria($label, $primaryRow)
            : EditorFieldNavigation::goToFieldAria($label);

        return '<button type="button" class="contentguard-warning-field" '
            . EditorFieldNavigation::fieldTriggerAttributes($fieldKey, $layout, $primaryRow)
            . ' aria-label="' . self::escapeAttr($aria) . '">'
            . self::escapeHtml($label)
            . '</button> — '
            . self::escapeHtml($message !== '' ? $message : 'This field is required.')
            . EditorFieldNavigation::rowButtonsHtml($fieldKey, $label, $layout, $rows);
    }

    public static function classicNoticeHtml(array $issues): string
    {
        $inner = self::noticeHtml($issues);
        if ($inner === '') {
            return '';
        }

        return '<div class="notice notice-error contentguard-audit-blockers-notice">' . $inner . '</div>';
    }

    public static function canAccess(int $postId, bool $canManage, bool $canEditPost): bool
    {
        return $postId > 0 && $canManage && $canEditPost;
    }

    /**
     * @param AuditFinding[] $findings
     * @param array<string, string> $fieldLabels
     * @return list<array{message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>
     */
    public static function resolve(
        array $findings,
        int $postId,
        array $fieldLabels,
        bool $canManage,
        bool $canEditPost,
    ): array {
        if (!self::canAccess($postId, $canManage, $canEditPost)) {
            return array();
        }

        return self::fromFindings($findings, $postId, $fieldLabels);
    }

    private static function humanLabel(string $label, string $fieldKey): string
    {
        $label = trim($label);
        if ($label === '' || $label === $fieldKey || EditorFieldNavigation::isSafeFieldKey($label)) {
            return '';
        }

        return $label;
    }

    private static function escapeHtml(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function escapeAttr(string $value): string
    {
        if (function_exists('esc_attr')) {
            return esc_attr($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
