<?php
/**
 * PHPUnit bootstrap (no WordPress).
 */

declare(strict_types=1);

// Satisfy direct-access guards in Application / Infrastructure / Admin PHP files.
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('_nx')) {
    function _nx(string $single, string $plural, int $number, string $context, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(mixed $text): string
    {
        if (is_object($text) || is_array($text)) {
            return '';
        }

        $text = (string) $text;
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/[\r\n\t ]+/', ' ', $text) ?? $text;

        return trim($text);
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $text): string
    {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text) ?? $text;
        $text = strip_tags($text);

        return trim($text);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        $key = strtolower($key);

        return (string) preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        $url = trim(str_replace(array("\r", "\n", "\t"), '', $url));
        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
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

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $data): string
    {
        $data = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $data) ?? $data;
        $data = preg_replace('/\s+on\w+\s*=\s*("|\').*?\1/i', '', $data) ?? $data;

        return $data;
    }
}

if (!function_exists('acf_verify_ajax')) {
    function acf_verify_ajax(): bool
    {
        if (!array_key_exists('contentlatch_test_acf_ajax_nonce', $GLOBALS)) {
            return true;
        }

        return (bool) $GLOBALS['contentlatch_test_acf_ajax_nonce'];
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('map_deep')) {
    function map_deep(mixed $value, callable $callback): mixed
    {
        if (is_array($value)) {
            foreach ($value as $index => $item) {
                $value[$index] = map_deep($item, $callback);
            }

            return $value;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $property => $item) {
                $value->{$property} = map_deep($item, $callback);
            }

            return $value;
        }

        return $callback($value);
    }
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_readable($autoload)) {
    fwrite(STDERR, "Run `composer install` before running tests.\n");
    exit(1);
}

require_once $autoload;
