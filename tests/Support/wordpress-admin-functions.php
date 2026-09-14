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

if (!function_exists('get_the_title')) {
    function get_the_title(int|string $post = 0): string
    {
        $titles = $GLOBALS['contentguard_test_titles'] ?? array();

        return (string) ($titles[(int) $post] ?? '');
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(int|string $post = 0, string $context = 'display'): string|null
    {
        unset($context);
        $links = $GLOBALS['contentguard_test_edit_links'] ?? array();
        if (!array_key_exists((int) $post, $links)) {
            return null;
        }

        $link = $links[(int) $post];

        return is_string($link) && $link !== '' ? $link : null;
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        unset($domain);

        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $data): string
    {
        return $data;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(string $key, string $value, string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . rawurlencode($key) . '=' . rawurlencode($value);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('paginate_links')) {
    /**
     * @param array<string, mixed> $args
     */
    function paginate_links(array $args = array()): string
    {
        $base = (string) ($args['base'] ?? 'paged=%#%');

        return '<a class="page-numbers" href="' . $base . '">2</a>';
    }
}
