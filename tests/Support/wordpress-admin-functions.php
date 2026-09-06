<?php
/**
 * Minimal WordPress admin helpers for view tests.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

if (!defined('CONTENTGUARD_DIR')) {
    define('CONTENTGUARD_DIR', dirname(__DIR__, 2) . '/');
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html(__($text, $domain));
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr(__($text, $domain));
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_js')) {
    function esc_js(string $text): string
    {
        return $text;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'http://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url(string $url, string $action = '-1'): string
    {
        unset($action);

        return $url . (str_contains($url, '?') ? '&' : '?') . '_wpnonce=testnonce';
    }
}

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $display = true): string
    {
        $html = (string) $selected === (string) $current ? ' selected="selected"' : '';
        if ($display) {
            echo $html;
        }

        return $html;
    }
}

if (!function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $display = true): string
    {
        $html = $checked == $current ? ' checked="checked"' : '';
        if ($display) {
            echo $html;
        }

        return $html;
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
    {
        unset($action, $referer);
        $html = '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="testnonce">';
        if ($echo) {
            echo $html;
        }

        return $html;
    }
}

if (!function_exists('disabled')) {
    function disabled(mixed $disabled, mixed $current = true, bool $echo = true): string
    {
        $html = !empty($disabled) === !empty($current) ? ' disabled="disabled"' : '';
        if ($echo) {
            echo $html;
        }

        return $html;
    }
}
