<?php
/**
 * Minimal WordPress admin helpers for view tests.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

if (!defined('CONTENTLATCH_DIR')) {
    define('CONTENTLATCH_DIR', dirname(__DIR__, 2) . '/');
}

if (!function_exists('wp_slash')) {
    /**
     * @param mixed $value
     * @return mixed
     */
    function wp_slash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }

        return is_string($value) ? addslashes($value) : $value;
    }
}

if (!function_exists('wp_unslash')) {
    /**
     * @param mixed $value
     * @return mixed
     */
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
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

if (!function_exists('wp_verify_nonce')) {
    /**
     * Test double for RulesController admin-post handlers.
     * Accepts the fixtures used in unit tests; rejects empty/forged values.
     *
     * @param mixed $nonce
     * @param mixed $action
     */
    function wp_verify_nonce($nonce = '', $action = -1): int|false
    {
        unset($action);
        $nonce = is_scalar($nonce) ? (string) $nonce : '';
        if ($nonce === '' || $nonce === '0') {
            return false;
        }

        return ($nonce === 'ok' || $nonce === 'testnonce') ? 1 : false;
    }
}

if (!function_exists('check_ajax_referer')) {
    /**
     * Test double for ContentLatch AJAX handlers. Mirrors Core: reads the query
     * arg, verifies via wp_verify_nonce, returns false on failure when $stop is false.
     * Does not always succeed.
     *
     * @param mixed $action
     * @param mixed $query_arg
     */
    function check_ajax_referer($action = -1, $query_arg = '_wpnonce', bool $stop = true): int|false
    {
        $query_arg = is_scalar($query_arg) ? (string) $query_arg : '_wpnonce';
        $raw       = null;
        if (isset($_REQUEST[$query_arg])) {
            $raw = $_REQUEST[$query_arg];
        } elseif (isset($_POST[$query_arg])) {
            $raw = $_POST[$query_arg];
        } elseif (isset($_GET[$query_arg])) {
            $raw = $_GET[$query_arg];
        }

        $nonce  = is_scalar($raw) ? sanitize_text_field(wp_unslash((string) $raw)) : '';
        $result = wp_verify_nonce($nonce, is_scalar($action) ? (string) $action : -1);
        if ($result === false && $stop) {
            exit;
        }

        return $result;
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
        $titles = $GLOBALS['contentlatch_test_titles'] ?? array();

        return (string) ($titles[(int) $post] ?? '');
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(int|string $post = 0, string $context = 'display'): string|null
    {
        unset($context);
        $links = $GLOBALS['contentlatch_test_edit_links'] ?? array();
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
        $data = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $data) ?? $data;
        $data = preg_replace('/\s+on\w+\s*=\s*("|\').*?\1/i', '', $data) ?? $data;

        return $data;
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return '';
        }

        return $email;
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title, string $fallback = ''): string
    {
        $title = strtolower(strip_tags($title));
        $title = preg_replace('/[^a-z0-9_\-]+/', '-', $title) ?? '';
        $title = trim($title, '-');

        return $title !== '' ? $title : $fallback;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(mixed $key, mixed $value = '', mixed $url = ''): string
    {
        if (is_array($key)) {
            $url   = (string) $value;
            $query = $key;
        } else {
            $url   = (string) $url;
            $query = array((string) $key => $value);
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        $parts     = array();
        foreach ($query as $name => $item) {
            $parts[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $item);
        }

        return $url . $separator . implode('&', $parts);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_send_json')) {
    /**
     * @param mixed $response
     */
    function wp_send_json(mixed $response, int $status = 200): void
    {
        $GLOBALS['contentlatch_test_json'] = array(
            'response' => $response,
            'status'   => $status,
        );
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
