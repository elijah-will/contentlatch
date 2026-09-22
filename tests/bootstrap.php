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

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_readable($autoload)) {
    fwrite(STDERR, "Run `composer install` before running tests.\n");
    exit(1);
}

require_once $autoload;
