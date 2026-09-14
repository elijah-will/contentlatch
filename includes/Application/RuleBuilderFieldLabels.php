<?php
/**
 * Rule Builder field dropdown labels.
 *
 * Optgroups use catalog group_label. Options under a group show the leaf
 * label so hierarchy is not duplicated. Preview keeps the full breadcrumb.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

final class RuleBuilderFieldLabels
{
    /**
     * @param array<string, mixed> $field
     */
    public static function optionLabel(array $field): string
    {
        $grouped = self::groupLabel($field) !== '';
        $label   = $grouped
            ? trim((string) ($field['label'] ?? ''))
            : self::breadcrumbOrLabel($field);

        return self::withRowSuffix($label, $field);
    }

    /**
     * @param array<string, mixed> $field
     */
    public static function previewLabel(array $field): string
    {
        return self::withRowSuffix(self::breadcrumbOrLabel($field), $field);
    }

    /**
     * @param array<string, mixed> $field
     */
    public static function groupLabel(array $field): string
    {
        return trim((string) ($field['group_label'] ?? ''));
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function breadcrumbOrLabel(array $field): string
    {
        $breadcrumb = trim((string) ($field['breadcrumb'] ?? ''));
        if ($breadcrumb !== '') {
            return $breadcrumb;
        }

        $label = trim((string) ($field['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        return (string) ($field['name'] ?? $field['key'] ?? '');
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function withRowSuffix(string $label, array $field): string
    {
        if ($label === '') {
            return $label;
        }

        if (($field['container'] ?? '') === 'repeater' && !str_contains($label, '(every row)')) {
            /* translators: Suffix appended to repeater field labels in the Rule Builder. */
            $label .= ' ' . I18n::translate('(every row)');
        }

        if (($field['container'] ?? '') === 'flexible_content') {
            $layoutLabel = trim((string) ($field['layout_label'] ?? ''));
            if ($layoutLabel === '') {
                $layoutLabel = (string) ($field['layout'] ?? I18n::translate('layout'));
            }
            /* translators: %s: Flexible Content layout label. */
            $suffix = I18n::sprintf(I18n::translate('(every %s row)'), $layoutLabel);
            if (!str_contains($label, $suffix)) {
                $label .= ' ' . $suffix;
            }
        }

        return $label;
    }
}
