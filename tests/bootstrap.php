<?php
/**
 * PHPUnit bootstrap (no WordPress).
 */

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_readable($autoload)) {
    fwrite(STDERR, "Run `composer install` before running tests.\n");
    exit(1);
}

require_once $autoload;
