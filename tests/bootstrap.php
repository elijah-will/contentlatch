<?php
/**
 * PHPUnit bootstrap (no WordPress).
 */

declare(strict_types=1);

// Satisfy direct-access guards in Application / Infrastructure / Admin PHP files.
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_readable($autoload)) {
    fwrite(STDERR, "Run `composer install` before running tests.\n");
    exit(1);
}

require_once $autoload;
