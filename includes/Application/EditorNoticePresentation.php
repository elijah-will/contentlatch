<?php
/**
 * Shared editor notice chrome for warning and blocking issues.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

final class EditorNoticePresentation
{
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_BLOCKING = 'blocking';

    public static function title(string $severity): string
    {
        return self::translate('ContentGuard') . ' · ' . self::severityLabel($severity);
    }

    public static function countLabel(string $severity, int $count): string
    {
        if ($count <= 1) {
            return '';
        }

        if ($severity === self::SEVERITY_WARNING) {
            return sprintf(self::translate('%d warnings'), $count);
        }

        return sprintf(self::translate('%d blocking issues'), $count);
    }

    /**
     * @param list<string> $itemHtml Already-escaped item markup.
     */
    public static function noticeHtml(string $severity, array $itemHtml): string
    {
        if ($itemHtml === array()) {
            return '';
        }

        $root = self::rootClass($severity);
        $html = '<div class="' . $root . '">';
        $html .= '<p class="' . $root . '__title">' . self::escapeHtml(self::title($severity)) . '</p>';

        $count = self::countLabel($severity, count($itemHtml));
        if ($count !== '') {
            $html .= '<p class="' . $root . '__count">' . self::escapeHtml($count) . '</p>';
        }

        $html .= '<ul class="' . $root . '__list">';
        foreach ($itemHtml as $item) {
            $html .= '<li>' . $item . '</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    /**
     * @param list<string> $itemText
     */
    public static function noticeText(string $severity, array $itemText): string
    {
        if ($itemText === array()) {
            return '';
        }

        $lines = array(self::title($severity));
        $count = self::countLabel($severity, count($itemText));
        if ($count !== '') {
            $lines[] = $count;
        }

        foreach ($itemText as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    public static function issueText(string $label, string $message): string
    {
        $label   = trim($label);
        $message = trim($message);

        if ($label !== '' && $message !== '') {
            return $label . ' — ' . $message;
        }

        return $message !== '' ? $message : $label;
    }

    private static function severityLabel(string $severity): string
    {
        if ($severity === self::SEVERITY_WARNING) {
            return self::translate('Warning');
        }

        return self::translate('Blocking');
    }

    private static function rootClass(string $severity): string
    {
        return $severity === self::SEVERITY_WARNING
            ? 'contentguard-editor-warnings'
            : 'contentguard-audit-blockers';
    }

    private static function translate(string $text): string
    {
        return function_exists('__') ? __($text, 'contentguard') : $text;
    }

    private static function escapeHtml(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
