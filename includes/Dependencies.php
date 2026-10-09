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

    public function acfIsInstalled(): bool
    {
        return defined('ACF_VERSION');
    }

    public function acfMeetsMinimum(): bool
    {
        if (!$this->acfIsInstalled()) {
            return false;
        }

        return version_compare((string) ACF_VERSION, Plugin::MIN_ACF, '>=');
    }

    /**
     * True when PHP and WordPress meet ContentLatch minimums.
     *
     * ACF is optional: Core validation boots without it. ACF availability is
     * gated separately via acfMeetsMinimum() in AcfIntegration.
     */
    public function canBootIntegrations(): bool
    {
        return $this->phpMeetsMinimum() && $this->wordpressMeetsMinimum();
    }

    public function registerAdminNotices(): void
    {
        if (!$this->phpMeetsMinimum() || !$this->wordpressMeetsMinimum()) {
            add_action('admin_notices', array($this, 'renderAdminNotice'));

            return;
        }

        // ACF absent: no notice (Core features remain available).
        // ACF present but too old: warn that only ACF integration is unavailable.
        if ($this->acfIsInstalled() && !$this->acfMeetsMinimum()) {
            add_action('admin_notices', array($this, 'renderAdminNotice'));
        }
    }

    public function renderAdminNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $message = $this->noticeMessage();
        if ($message === '') {
            return;
        }

        printf(
            '<div class="notice %1$s"><p>%2$s</p></div>',
            esc_attr($this->noticeClass()),
            esc_html($message)
        );
    }

    public function noticeClass(): string
    {
        if (!$this->phpMeetsMinimum() || !$this->wordpressMeetsMinimum()) {
            return 'notice-error';
        }

        if ($this->acfIsInstalled() && !$this->acfMeetsMinimum()) {
            return 'notice-warning';
        }

        return 'notice-error';
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

        if ($this->acfIsInstalled() && !$this->acfMeetsMinimum()) {
            return sprintf(
                /* translators: %s: minimum ACF version */
                __('ContentLatch ACF integration requires Advanced Custom Fields %s or newer. WordPress Core field validation remains available.', 'contentlatch'),
                Plugin::MIN_ACF
            );
        }

        return '';
    }
}
