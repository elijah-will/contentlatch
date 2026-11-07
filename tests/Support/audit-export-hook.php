<?php
/**
 * Records admin-post registration when the shared add_action stub is absent.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

if (!function_exists('add_action')) {
    function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): void
    {
        unset($priority, $accepted_args);
        $GLOBALS['contentlatch_test_actions'][] = array($hook, $callback);
    }
}
