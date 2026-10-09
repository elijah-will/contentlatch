<?php
/**
 * Stubs for Dependencies admin notice registration tests.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

if (!function_exists('add_action')) {
    function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): void
    {
        \ContentLatch\Tests\Unit\DependenciesTest::recordAction($hook, $callback);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        if ($capability === 'activate_plugins') {
            return (bool) ($GLOBALS['contentlatch_test_can_activate_plugins'] ?? false);
        }

        return false;
    }
}
