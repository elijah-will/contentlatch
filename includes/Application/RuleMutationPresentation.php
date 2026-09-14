<?php
/**
 * User-facing rule mutation messages. Technical detail stays in WP_DEBUG logs.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

use ContentGuard\Application\Exception\ForbiddenRuleMutationException;
use ContentGuard\Application\Exception\RulePersistenceException;
use ContentGuard\Domain\Exception\InvalidRuleException;
use Throwable;

final class RuleMutationPresentation
{
    public static function addedMessage(): string
    {
        return I18n::translate('Rule added.');
    }

    public static function savedMessage(): string
    {
        return I18n::translate('Rule saved.');
    }

    public static function saveFailureMessage(Throwable $exception, bool $creating = false): string
    {
        $prefix = $creating
            ? I18n::translate('We could not add this rule. ')
            : I18n::translate('We could not save this rule. ');

        if ($exception instanceof InvalidRuleException || $exception instanceof ForbiddenRuleMutationException) {
            return $prefix . $exception->getMessage();
        }

        if ($exception instanceof RulePersistenceException && $exception->getMessage() === 'Rule not found.') {
            return $prefix . I18n::translate('It is no longer available. Your entered values have been preserved so you can try again.');
        }

        return $prefix . I18n::translate('Your entered values have been preserved so you can correct the issue and try again.');
    }

    public static function unreadAfterSaveMessage(bool $creating): string
    {
        $prefix = $creating
            ? I18n::translate('We could not add this rule. ')
            : I18n::translate('We could not save this rule. ');

        return $prefix . I18n::translate('The rule could not be read after saving. Your entered values have been preserved so you can try again.');
    }

    public static function missingRuleMessage(): string
    {
        return I18n::translate('We could not open this rule. It may have been deleted or could not be read.');
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

        error_log('ContentGuard ' . $context . ': ' . $exception->getMessage());
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
            : json_encode($safe);

        error_log('ContentGuard ' . $context . ($encoded ? ': ' . $encoded : ''));
    }
}
