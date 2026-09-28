<?php
/**
 * Runtime dependency checks (PHP, WordPress, ACF).
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch;

defined('ABSPATH') || exit;

final class Dependencies
{
    public function phpMeetsMinimum(): bool
    {
        return version_compare(PHP_VERSION, Plugin::MIN_PHP, '>=');
    }

    public function wordpressMeetsMinimum(): bool
    {
        global $wp_version;

        if (!isset($wp_version) || !is_string($wp_version)) {
            return false;
        }

        return version_compare($wp_version, Plugin::MIN_WP, '>=');
    }

    public function acfMeetsMinimum(): bool
    {
        if (!defined('ACF_VERSION')) {
            return false;
        }

        return version_compare((string) ACF_VERSION, Plugin::MIN_ACF, '>=');
    }

    public function canBootIntegrations(): bool
    {
        return $this->phpMeetsMinimum()
            && $this->wordpressMeetsMinimum()
            && $this->acfMeetsMinimum();
    }

    public function registerAdminNotices(): void
    {
        if ($this->canBootIntegrations()) {
            return;
        }

        add_action('admin_notices', array($this, 'renderAdminNotice'));
    }

    public function renderAdminNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>' . esc_html($this->noticeMessage()) . '</p></div>';
    }

    public function noticeMessage(): string
    {
        if (!$this->phpMeetsMinimum()) {
            return sprintf(
                /* translators: %s: minimum PHP version */
                __('ContentLatch requires PHP %s or higher.', 'contentlatch'),
                Plugin::MIN_PHP
            );
        }

        if (!$this->wordpressMeetsMinimum()) {
            return sprintf(
                /* translators: %s: minimum WordPress version */
                __('ContentLatch requires WordPress %s or higher.', 'contentlatch'),
                Plugin::MIN_WP
            );
        }

        if (!defined('ACF_VERSION')) {
            return __('ContentLatch requires Advanced Custom Fields 6.0 or higher (Free or Pro).', 'contentlatch');
        }

        return sprintf(
            /* translators: %s: minimum ACF version */
            __('ContentLatch requires Advanced Custom Fields %s or higher (Free or Pro).', 'contentlatch'),
            Plugin::MIN_ACF
        );
    }
}
