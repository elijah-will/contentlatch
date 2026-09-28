<?php
/**
 * User-facing rule mutation messages. Technical detail stays in WP_DEBUG logs.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application;

defined('ABSPATH') || exit;

use ContentLatch\Application\Exception\ForbiddenRuleMutationException;
use ContentLatch\Application\Exception\RulePersistenceException;
use ContentLatch\Domain\Exception\InvalidRuleException;
use Throwable;

final class RuleMutationPresentation
{
    public static function addedMessage(): string
    {
        return __('Rule added.', 'contentlatch');
    }

    public static function savedMessage(): string
    {
        return __('Rule saved.', 'contentlatch');
    }

    public static function saveFailureMessage(Throwable $exception, bool $creating = false): string
    {
        $prefix = $creating
            ? __('We could not add this rule. ', 'contentlatch')
            : __('We could not save this rule. ', 'contentlatch');

        if ($exception instanceof InvalidRuleException || $exception instanceof ForbiddenRuleMutationException) {
            return $prefix . $exception->getMessage();
        }

        if ($exception instanceof RulePersistenceException && $exception->getMessage() === 'Rule not found.') {
            return $prefix . __('It is no longer available. Your entered values have been preserved so you can try again.', 'contentlatch');
        }

        return $prefix . __('Your entered values have been preserved so you can correct the issue and try again.', 'contentlatch');
    }

    public static function unreadAfterSaveMessage(bool $creating): string
    {
        $prefix = $creating
            ? __('We could not add this rule. ', 'contentlatch')
            : __('We could not save this rule. ', 'contentlatch');

        return $prefix . __('The rule could not be read after saving. Your entered values have been preserved so you can try again.', 'contentlatch');
    }

    public static function missingRuleMessage(): string
    {
        return __('We could not open this rule. It may have been deleted or could not be read.', 'contentlatch');
    }

    /**
     * @param array<string, mixed>|null $draft
     * @return array<string, mixed>|null
     */
    public static function draftForNewRule(?array $draft): ?array
    {
        if ($draft === null) {
            return null;
        }

        $draft['id']      = '';
        $draft['rule_id'] = '';

        return $draft;
    }

    public static function logFailure(string $context, Throwable $exception): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG || !function_exists('error_log')) {
            return;
        }

        error_log('ContentLatch ' . $context . ': ' . $exception->getMessage());
    }

    /**
     * @param array<string, mixed> $safe
     */
    public static function logDebug(string $context, array $safe = array()): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG || !function_exists('error_log')) {
            return;
        }

        unset($safe['json'], $safe['document'], $safe['post_content'], $safe['meta']);

        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($safe)
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Non-WP / early-bootstrap fallback when wp_json_encode is unavailable.
            : json_encode($safe);

        error_log('ContentLatch ' . $context . ($encoded ? ': ' . $encoded : ''));
    }
}
