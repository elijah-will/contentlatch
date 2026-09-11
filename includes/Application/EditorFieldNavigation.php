<?php
/**
 * Safe finding → ACF field navigation helpers.
 *
 * Targets ACF fields by leaf field key, including Group, Repeater,
 * Flexible Content, and Clone children. Row indexes are not placed in URLs.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

use ContentGuard\Domain\FieldRef;

final class EditorFieldNavigation
{
    public const QUERY_ARG     = 'contentguard_field';
    public const AUDIT_RUN_ARG = 'contentguard_run';

    public static function isSafeFieldKey(string $fieldKey): bool
    {
        return FieldRef::isSafeFieldKey($fieldKey);
    }

    /**
     * ACF field key or allowlisted Core id. Not a widening of isSafeFieldKey().
     */
    public static function navigationId(?string $fieldId): string
    {
        $acf = self::navigableFieldKey($fieldId);
        if ($acf !== '') {
            return $acf;
        }

        return EditorCoreNavigation::id($fieldId);
    }

    public static function isQueryTarget(string $fieldId): bool
    {
        return self::isSafeFieldKey($fieldId) || EditorCoreNavigation::isId($fieldId);
    }

    public static function isSafeLayoutName(string $layout): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+$/', $layout);
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function requestedFieldKey(array $request): string
    {
        $raw = isset($request[self::QUERY_ARG]) ? (string) $request[self::QUERY_ARG] : '';

        return self::isQueryTarget($raw) ? $raw : '';
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function requestedRunId(array $request): int
    {
        return self::sanitizeRunId($request[self::AUDIT_RUN_ARG] ?? null);
    }

    public static function sanitizeRunId(mixed $runId): int
    {
        if (is_int($runId)) {
            return $runId > 0 ? $runId : 0;
        }

        if (is_string($runId) && ctype_digit($runId)) {
            $runId = (int) $runId;

            return $runId > 0 ? $runId : 0;
        }

        return 0;
    }

    /**
     * Edit links must stay on the WordPress post editor.
     *
     * WordPress add_query_arg() falls back to REQUEST_URI when the URL
     * argument is missing/false. That would keep the user on Audit and can
     * reintroduce the reserved `post_type` query var. Always append locally.
     */
    public static function appendToEditUrl(string $editUrl, string $fieldKey, int $runId = 0): string
    {
        $editUrl = self::normalizeEditorUrl($editUrl);
        if ($editUrl === '') {
            return '';
        }

        if (self::isQueryTarget($fieldKey) && !str_contains($editUrl, self::QUERY_ARG . '=')) {
            $separator = str_contains($editUrl, '?') ? '&' : '?';
            $editUrl  .= $separator . self::QUERY_ARG . '=' . rawurlencode($fieldKey);
        }

        $runId = self::sanitizeRunId($runId);
        if ($runId > 0 && !str_contains($editUrl, self::AUDIT_RUN_ARG . '=')) {
            $separator = str_contains($editUrl, '?') ? '&' : '?';
            $editUrl  .= $separator . self::AUDIT_RUN_ARG . '=' . $runId;
        }

        return $editUrl;
    }

    public static function normalizeEditorUrl(string $editUrl): string
    {
        $editUrl = trim($editUrl);
        if ($editUrl === '') {
            return '';
        }

        if (self::looksLikeAuditAdminUrl($editUrl)) {
            return '';
        }

        return $editUrl;
    }

    public static function looksLikeAuditAdminUrl(string $url): bool
    {
        return str_contains($url, 'page=contentguard-audit');
    }

    public static function navigableFieldKey(?string $fieldKey): string
    {
        $fieldKey = trim((string) $fieldKey);

        return self::isSafeFieldKey($fieldKey) ? $fieldKey : '';
    }

    public static function goToFieldAria(string $label): string
    {
        $name = $label !== '' ? $label : 'field';

        return sprintf('Go to field: %s', $name);
    }

    public static function goToLayoutRowAria(string $label, int $row): string
    {
        $name = $label !== '' ? $label : 'field';
        $row  = self::sanitizeDisplayRow($row);
        if ($row <= 0) {
            return self::goToFieldAria($name);
        }

        return sprintf('Go to %s, row %d', $name, $row);
    }

    public static function sanitizeDisplayRow(mixed $row): int
    {
        if (is_int($row)) {
            return $row > 0 ? $row : 0;
        }

        if (is_string($row) && ctype_digit($row)) {
            $row = (int) $row;

            return $row > 0 ? $row : 0;
        }

        if (is_float($row) && $row > 0 && floor($row) === $row) {
            return (int) $row;
        }

        return 0;
    }

    /**
     * @return list<int>
     */
    public static function sanitizeDisplayRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            $row = self::sanitizeDisplayRow($rows);

            return $row > 0 ? array($row) : array();
        }

        $safe = array();
        foreach ($rows as $row) {
            $row = self::sanitizeDisplayRow($row);
            if ($row > 0) {
                $safe[$row] = $row;
            }
        }

        $safe = array_values($safe);
        sort($safe, SORT_NUMERIC);

        return $safe;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function layoutFromContext(array $context): string
    {
        $layout = trim((string) ($context['layout'] ?? ''));

        return self::isSafeLayoutName($layout) ? $layout : '';
    }

    /**
     * Evaluation-time Flex rows only. Repeater/Group context has no layout.
     *
     * @param array<string, mixed> $context
     * @return list<int>
     */
    public static function displayRowsFromContext(array $context): array
    {
        if (self::layoutFromContext($context) === '') {
            return array();
        }

        return self::sanitizeDisplayRows($context['display_row'] ?? null);
    }

    /**
     * Snapshot text such as "Title is required in 2 Hero rows (rows 1, 3)."
     * Repeater snapshots ("in 3 rows (rows 1, 3, 5)") are ignored.
     *
     * @return list<int>
     */
    public static function flexDisplayRowsFromSnapshot(string $message): array
    {
        if (preg_match('/\bin\s+\d+\s+\S+\s+rows\s+\(rows?\s+([0-9,\s]+)\)/i', $message, $matches)) {
            $rows = array();
            foreach (preg_split('/\s*,\s*/', (string) $matches[1]) ?: array() as $part) {
                $rows[] = $part;
            }

            return self::sanitizeDisplayRows($rows);
        }

        if (preg_match('/\bin\s+\S+\s+row\s+(\d+)\b/i', $message, $matches)) {
            return self::sanitizeDisplayRows($matches[1]);
        }

        return array();
    }

    public static function snapshotMessageWithoutRows(string $message): string
    {
        $stripped = preg_replace('/\s*\(rows?\s+[0-9,\s]+\)\.?\s*$/i', '.', $message);
        if (!is_string($stripped) || $stripped === '') {
            return $message;
        }

        return $stripped;
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     * @return list<int>
     */
    public static function mergeDisplayRows(array $left, array $right): array
    {
        return self::sanitizeDisplayRows(array_merge($left, $right));
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function withEvaluationRowTargets(array $item, array $context): array
    {
        $layout = self::layoutFromContext($context);
        $rows   = self::displayRowsFromContext($context);
        if ($layout !== '') {
            $item['layout'] = $layout;
        }
        if ($rows !== array()) {
            $item['affectedRows'] = $rows;
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function withSnapshotRowTargets(array $item, string $message): array
    {
        $rows = self::flexDisplayRowsFromSnapshot($message);
        if ($rows !== array()) {
            $item['affectedRows'] = $rows;
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<int>
     */
    public static function affectedRowsFromItem(array $item): array
    {
        return self::sanitizeDisplayRows($item['affectedRows'] ?? array());
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function layoutFromItem(array $item): string
    {
        $layout = trim((string) ($item['layout'] ?? ''));

        return self::isSafeLayoutName($layout) ? $layout : '';
    }

    /**
     * @param array<string, mixed> $into
     * @param array<string, mixed> $from
     * @return array<string, mixed>
     */
    public static function mergeRowTargets(array $into, array $from): array
    {
        $layout = self::layoutFromItem($into);
        if ($layout === '') {
            $layout = self::layoutFromItem($from);
        }
        if ($layout !== '') {
            $into['layout'] = $layout;
        }

        $rows = self::mergeDisplayRows(
            self::affectedRowsFromItem($into),
            self::affectedRowsFromItem($from)
        );
        if ($rows !== array()) {
            $into['affectedRows'] = $rows;
        }

        return $into;
    }

    /**
     * @param list<int> $rows
     */
    public static function primaryDisplayRow(array $rows): int
    {
        $rows = self::sanitizeDisplayRows($rows);

        return $rows[0] ?? 0;
    }

    /**
     * Clickable issue line. ACF uses data-contentguard-field; Core uses
     * data-contentguard-core and only when the current surface supports it.
     *
     * @param list<int> $rows
     */
    public static function clickableIssueHtml(
        string $fieldId,
        string $label,
        string $message,
        string $fallbackMessage,
        string $surface,
        string $layout = '',
        array $rows = array(),
    ): string {
        $label   = trim($label);
        $message = trim($message);
        $text    = EditorNoticePresentation::issueText($label, $message);
        if ($text === '') {
            $text = $fallbackMessage;
        }

        $acf     = self::navigableFieldKey($fieldId);
        $surface = EditorCoreNavigation::normalizeSurface($surface);
        $core    = EditorCoreNavigation::isSupported($fieldId, $surface)
            ? EditorCoreNavigation::id($fieldId)
            : '';

        if ($label === '' || ($acf === '' && $core === '')) {
            return self::escapeHtml($text);
        }

        $primaryRow = self::primaryDisplayRow($rows);
        $aria       = $acf !== '' && count($rows) === 1
            ? self::goToLayoutRowAria($label, $primaryRow)
            : self::goToFieldAria($label);
        $attrs      = $acf !== ''
            ? self::fieldTriggerAttributes($acf, $layout, $primaryRow)
            : EditorCoreNavigation::triggerAttributes($core);
        $suffix     = $acf !== ''
            ? self::rowButtonsHtml($acf, $label, $layout, $rows)
            : '';

        return '<button type="button" class="contentguard-warning-field" '
            . $attrs
            . ' aria-label="' . self::escapeAttr($aria) . '">'
            . self::escapeHtml($label)
            . '</button> — '
            . self::escapeHtml($message !== '' ? $message : $fallbackMessage)
            . $suffix;
    }

    public static function isClickableTarget(string $fieldId, string $label, string $surface): bool
    {
        if (trim($label) === '') {
            return false;
        }

        if (self::navigableFieldKey($fieldId) !== '') {
            return true;
        }

        return EditorCoreNavigation::isSupported($fieldId, $surface);
    }

    public static function fieldTriggerAttributes(string $fieldKey, string $layout = '', int $displayRow = 0): string
    {
        $fieldKey = self::navigableFieldKey($fieldKey);
        if ($fieldKey === '') {
            return '';
        }

        $attrs = 'data-contentguard-field="' . self::escapeAttr($fieldKey) . '"';
        if (self::isSafeLayoutName($layout)) {
            $attrs .= ' data-contentguard-layout="' . self::escapeAttr($layout) . '"';
        }

        $displayRow = self::sanitizeDisplayRow($displayRow);
        if ($displayRow > 0) {
            $attrs .= ' data-contentguard-display-row="' . $displayRow . '"';
        }

        return $attrs;
    }

    /**
     * Compact Flex row buttons. Hidden for a single affected row.
     *
     * @param list<int> $rows
     */
    public static function rowButtonsHtml(string $fieldKey, string $label, string $layout, array $rows): string
    {
        $fieldKey = self::navigableFieldKey($fieldKey);
        $rows     = self::sanitizeDisplayRows($rows);
        if ($fieldKey === '' || count($rows) < 2) {
            return '';
        }

        $buttons = array();
        foreach ($rows as $row) {
            $buttons[] = '<button type="button" class="contentguard-warning-field contentguard-warning-row" '
                . self::fieldTriggerAttributes($fieldKey, $layout, $row)
                . ' aria-label="' . self::escapeAttr(self::goToLayoutRowAria($label, $row)) . '">'
                . self::escapeHtml(sprintf('Row %d', $row))
                . '</button>';
        }

        return ' <span class="contentguard-warning-rows">' . implode('<span aria-hidden="true"> · </span>', $buttons) . '</span>';
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
