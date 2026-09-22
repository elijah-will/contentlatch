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

defined('ABSPATH') || exit;

use ContentGuard\Application\Audit\AuditFinding;
use ContentGuard\Application\Audit\AuditRepeaterCoordinates;
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
            $id   = AuditRepeaterCoordinates::messageWithoutPairs($item['message'])
                . "\0" . $item['fieldKey']
                . "\0" . $item['label'];
            if (isset($seen[$id])) {
                $items[$seen[$id]] = self::mergeNestedPresentation($items[$seen[$id]], $item);
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
            $message = __('This field is required.', 'contentguard');
        } else {
            $message = DomainMessages::present($message);
        }

        $fieldKey = EditorFieldNavigation::navigationId($result->fieldId);
        $label    = trim((string) ($result->context['field_label'] ?? ''));
        $chain    = AuditRepeaterCoordinates::instanceChain($result->context);
        if ($chain !== array()) {
            $message = AuditRepeaterCoordinates::formatSnapshot(
                self::nestedBaseMessage($result, $message),
                array($chain)
            );
        } else {
            $displayRow = EditorFieldNavigation::sanitizeDisplayRow($result->context['display_row'] ?? null);
            if ($displayRow > 0 && EditorFieldNavigation::layoutFromContext($result->context) === '') {
                /* translators: 1: Base validation message. 2: 1-based row number. */
                $message = sprintf(
                    __('%1$s in row %2$d.', 'contentguard'),
                    rtrim(self::nestedBaseMessage($result, $message), '.'),
                    $displayRow
                );
            }
        }

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
            $safe = EditorFieldNavigation::navigationId($key);
            if ($safe !== '') {
                $allowed[$safe] = true;
            }
        }

        if ($allowed === array()) {
            return array();
        }

        $scoped = array();
        foreach ($issues as $issue) {
            $key = EditorFieldNavigation::navigationId($issue['fieldKey'] ?? null);
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
    public static function payload(array $issues, ?string $surface = null): array
    {
        return array(
            'issues' => $issues,
            'html'   => self::noticeHtml($issues, $surface),
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
            $message = __('This field is required.', 'contentguard');
        } else {
            $message = DomainMessages::present($message);
        }

        $rawLabel = $fieldLabels[(string) $finding->ruleId . ':' . $finding->fieldKey]
            ?? $fieldLabels[$finding->fieldKey]
            ?? '';

        return EditorFieldNavigation::withFindingRowTargets(
            array(
                'message'  => $message,
                'label'    => self::humanLabel((string) $rawLabel, $finding->fieldKey),
                'fieldKey' => EditorFieldNavigation::navigationId($finding->fieldKey),
            ),
            $finding
        );
    }

    /**
     * @param array{message?: string, label?: string, fieldKey?: string} $issue
     */
    public static function isClickable(array $issue, ?string $surface = null): bool
    {
        $surface ??= EditorCoreNavigation::currentSurface();

        return EditorFieldNavigation::isClickableTarget(
            (string) ($issue['fieldKey'] ?? ''),
            (string) ($issue['label'] ?? ''),
            $surface
        );
    }

    /**
     * @param list<array{message?: string, label?: string, fieldKey?: string}> $issues
     */
    public static function noticeText(array $issues): string
    {
        if ($issues === array()) {
            return '';
        }

        $lines = array();
        foreach ($issues as $issue) {
            $line = self::issueText($issue);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return EditorNoticePresentation::noticeText(EditorNoticePresentation::SEVERITY_BLOCKING, $lines);
    }

    /**
     * Classic ACF global error: same wording as noticeText, with clickable
     * inline field labels. ACF renders this inside one <p>.
     *
     * @param list<array{message?: string, label?: string, fieldKey?: string, layout?: string, affectedRows?: list<int>, repeaterPath?: list<array{repeater: string, display_row: int}>, repeaterPathInvalid?: bool}> $issues
     */
    public static function classicValidationNotice(array $issues): string
    {
        if ($issues === array()) {
            return '';
        }

        $lines = array(EditorNoticePresentation::title(EditorNoticePresentation::SEVERITY_BLOCKING));
        $count = EditorNoticePresentation::countLabel(
            EditorNoticePresentation::SEVERITY_BLOCKING,
            count($issues)
        );
        if ($count !== '') {
            $lines[] = $count;
        }

        foreach ($issues as $issue) {
            $line = self::issueHtml($issue, EditorCoreNavigation::SURFACE_CLASSIC);
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

        return EditorNoticePresentation::issueText($label, $message);
    }

    /**
     * @param list<array{message?: string, label?: string, fieldKey?: string}> $issues
     */
    public static function noticeHtml(array $issues, ?string $surface = null): string
    {
        if ($issues === array()) {
            return '';
        }

        $surface = EditorCoreNavigation::normalizeSurface(
            $surface ?? EditorCoreNavigation::currentSurface()
        );
        $items   = array();
        foreach ($issues as $issue) {
            $items[] = self::issueHtml($issue, $surface);
        }

        return EditorNoticePresentation::noticeHtml(EditorNoticePresentation::SEVERITY_BLOCKING, $items);
    }

    /**
     * @param array{message?: string, label?: string, fieldKey?: string} $issue
     */
    public static function issueHtml(array $issue, ?string $surface = null): string
    {
        $surface ??= EditorCoreNavigation::currentSurface();

        return EditorFieldNavigation::clickableIssueHtml(
            (string) ($issue['fieldKey'] ?? ''),
            (string) ($issue['label'] ?? ''),
            (string) ($issue['message'] ?? ''),
            __('This field is required.', 'contentguard'),
            $surface,
            EditorFieldNavigation::layoutFromItem($issue),
            EditorFieldNavigation::affectedRowsFromItem($issue),
            EditorFieldNavigation::repeaterPathFromItem($issue),
            EditorFieldNavigation::isRepeaterPathBlocked($issue)
        );
    }

    public static function classicNoticeHtml(array $issues): string
    {
        $inner = self::noticeHtml($issues, EditorCoreNavigation::SURFACE_CLASSIC);
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

    /**
     * @param array<string, mixed> $into
     * @param array<string, mixed> $from
     * @return array<string, mixed>
     */
    private static function mergeNestedPresentation(array $into, array $from): array
    {
        $cells = array_merge(
            AuditRepeaterCoordinates::cellsFromSnapshot((string) ($into['message'] ?? '')),
            AuditRepeaterCoordinates::cellsFromSnapshot((string) ($from['message'] ?? ''))
        );
        if ($cells === array()) {
            return $into;
        }

        $base = AuditRepeaterCoordinates::messageWithoutPairs((string) ($into['message'] ?? ''));
        if ($base === '') {
            $base = AuditRepeaterCoordinates::messageWithoutPairs((string) ($from['message'] ?? ''));
        }

        $into['message'] = AuditRepeaterCoordinates::formatSnapshot(
            $base !== '' ? $base : __('This field is required.', 'contentguard'),
            $cells
        );

        return $into;
    }

    private static function nestedBaseMessage(EvaluationResult $result, string $fallback): string
    {
        if ($result->code === 'required') {
            $parts = preg_split('/\s*→\s*/u', (string) ($result->context['field_label'] ?? '')) ?: array();
            $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
            $label = $parts !== array() ? (string) $parts[count($parts) - 1] : '';
            if ($label !== '') {
                /* translators: %s: Field label. */
                return sprintf(__('%s is required.', 'contentguard'), $label);
            }
        }

        return $fallback !== '' ? $fallback : __('This field is required.', 'contentguard');
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
