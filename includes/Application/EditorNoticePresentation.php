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

    /**
     * Blocking copy for surfaces that can only deliver one text node
     * (Classic ACF global errors). A single issue stays one line. Multiple
     * issues reuse the shared ContentGuard · Blocking hierarchy.
     *
     * @param list<string> $itemText
     */
    public static function blockingNoticeText(array $itemText): string
    {
        $lines = array();
        foreach ($itemText as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        if ($lines === array()) {
            return '';
        }

        if (count($lines) === 1) {
            return $lines[0];
        }

        return self::noticeText(self::SEVERITY_BLOCKING, $lines);
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

    /**
     * Single evaluation-result line shared by Classic, ACF, and Gutenberg.
     *
     * Default required failures become "Label — This field is required.".
     * Custom messages keep their wording; the label is prefixed only when
     * it is not already the start of the message.
     */
    public static function issueLine(string $label, string $message, string $code = ''): string
    {
        $label   = trim($label);
        $message = trim($message);

        if ($label !== '' && $message !== '' && str_starts_with($message, $label . ' — ')) {
            return $message;
        }

        $required          = self::translate('This field is required.');
        $isDefaultRequired = $code === 'required'
            && ($message === '' || $message === $required || $message === 'This field is required.');
        $isLabelRequired   = $label !== '' && $message === $label . ' is required.';

        if ($label !== '' && ($isDefaultRequired || $isLabelRequired)) {
            return self::issueText($label, $required);
        }

        if ($label !== '' && $message !== '' && !str_starts_with($message, $label)) {
            return self::issueText($label, $message);
        }

        $line = $message !== '' ? $message : $label;

        return $line !== '' ? $line : self::translate('ContentGuard validation failed.');
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
