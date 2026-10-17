<?php
/**
 * Export metadata doubles, loaded only inside isolated adapter tests.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

function get_post(int $postId): ?object
{
    $state = &$GLOBALS['contentlatch_test_export'];
    $state['post_calls'][] = $postId;

    if (isset($state['cache']['posts'][$postId]) && empty($state['read_error']) && empty($state['missing'])) {
        return $state['cache']['posts'][$postId];
    }

    // Match wpdb::query(): not-ready returns before flush/num_queries; a real
    // attempt clears last_error, counts the query, then records a new failure.
    if (empty($GLOBALS['wpdb']->ready)) {
        return null;
    }

    $GLOBALS['wpdb']->num_queries = (int) ($GLOBALS['wpdb']->num_queries ?? 0) + 1;
    $GLOBALS['wpdb']->last_error  = '';
    if (isset($state['read_error'])) {
        $GLOBALS['wpdb']->last_error = $state['read_error'];
        return null;
    }
    if ($state['missing']) {
        return null;
    }

    // Emulate the post and meta caches touched by Core permission checks.
    $post = (object) array(
        'ID' => $postId,
        'post_title' => $state['title'],
        'post_status' => $state['status'],
        'post_content' => str_repeat('Body that must not be exported. ', 100),
    );
    $state['cache']['posts'][$postId] = $post;
    $state['cache']['post_meta'][$postId] = array('private' => 'metadata');
    return $post;
}

function get_edit_post_link(int $postId, string $context = 'display'): ?string
{
    $state = &$GLOBALS['contentlatch_test_export'];
    $state['edit_calls'][] = array($postId, $context);
    $state['cached_during_edit'] = isset($state['cache']['posts'][$postId]);
    if ($state['throw_on_edit']) {
        throw new \RuntimeException('An edit-link filter failed.');
    }

    return $state['edit_url'];
}

function wp_cache_delete(int $postId, string $group = ''): bool
{
    $state = &$GLOBALS['contentlatch_test_export'];
    $state['deleted'][] = array($postId, $group);
    unset($state['cache'][$group][$postId]);
    return true;
}
