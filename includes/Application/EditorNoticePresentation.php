<?php
/**
 * Shared editor notice chrome for warning and blocking issues.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application;

defined('ABSPATH') || exit;

final class EditorNoticePresentation
{
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_BLOCKING = 'blocking';

    public static function title(string $severity): string
    {
        return __('ContentLatch', 'contentlatch') . ' · ' . self::severityLabel($severity);
    }

    public static function countLabel(string $severity, int $count): string
    {
        if ($count <= 1) {
            return '';
        }

        if ($severity === self::SEVERITY_WARNING) {
            return sprintf(
                /* translators: %d: Number of warnings. */
                __('%d warnings', 'contentlatch'),
                $count
            );
        }

        return sprintf(
            /* translators: %d: Number of blocking issues. */
            __('%d blocking issues', 'contentlatch'),
            $count
        );
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
     * (Classic ACF global errors). Always includes ContentLatch · Blocking
     * so a single Core/condition-only issue matches Gutenberg identity.
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
        $message = DomainMessages::present(trim($message));

        if ($label !== '' && $message !== '' && str_starts_with($message, $label . ' — ')) {
            return $message;
        }

        $required          = __('This field is required.', 'contentlatch');
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

        return $line !== '' ? $line : __('ContentLatch validation failed.', 'contentlatch');
    }

    private static function severityLabel(string $severity): string
    {
        if ($severity === self::SEVERITY_WARNING) {
            return __('Warning', 'contentlatch');
        }

        return __('Blocking', 'contentlatch');
    }

    private static function rootClass(string $severity): string
    {
        return $severity === self::SEVERITY_WARNING
            ? 'contentlatch-editor-warnings'
            : 'contentlatch-audit-blockers';
    }

    private static function escapeHtml(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Allowlist for already-escaped notice markup. wp_kses_post() strips
     * <button> and data-contentlatch-* attributes, which removes click-to-focus.
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowedNoticeHtml(): array
    {
        $attrs = array(
            'class'                           => true,
            'id'                              => true,
            'type'                            => true,
            'role'                            => true,
            'aria-hidden'                     => true,
            'aria-label'                      => true,
            'data-contentlatch-field'         => true,
            'data-contentlatch-core'          => true,
            'data-contentlatch-layout'        => true,
            'data-contentlatch-display-row'   => true,
            'data-contentlatch-repeater-path' => true,
        );

        return array(
            'div'    => $attrs,
            'p'      => $attrs,
            'ul'     => $attrs,
            'li'     => $attrs,
            'span'   => $attrs,
            'button' => $attrs,
            'br'     => array(),
        );
    }

    public static function kses(string $html): string
    {
        if (function_exists('wp_kses')) {
            return wp_kses($html, self::allowedNoticeHtml());
        }

        return $html;
    }
}
