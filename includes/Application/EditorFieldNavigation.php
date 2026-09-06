<?php
/**
 * Safe finding → ACF field navigation helpers.
 *
 * Targets top-level ACF fields only. Nested Group/Repeater/Flexible/Clone
 * navigation is out of scope.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

final class EditorFieldNavigation
{
    public const QUERY_ARG     = 'contentguard_field';
    public const AUDIT_RUN_ARG = 'contentguard_run';

    public static function isSafeFieldKey(string $fieldKey): bool
    {
        return (bool) preg_match('/^field_[A-Za-z0-9]+$/', $fieldKey);
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function requestedFieldKey(array $request): string
    {
        $raw = isset($request[self::QUERY_ARG]) ? (string) $request[self::QUERY_ARG] : '';

        return self::isSafeFieldKey($raw) ? $raw : '';
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

        if (self::isSafeFieldKey($fieldKey) && !str_contains($editUrl, self::QUERY_ARG . '=')) {
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
}
