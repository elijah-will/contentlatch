<?php
/**
 * Gutenberg presentation for ContentLatch REST save blocking errors.
 *
 * Does not change REST validation. HTTP 400 with
 * contentlatch_validation_failed remains the source of truth. This class
 * only enqueues the editor script that replaces Gutenberg's generic save
 * notice with the existing ContentLatch blocking notice chrome.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Admin;

defined('ABSPATH') || exit;

use ContentLatch\Infrastructure\WordPress\RestSaveValidator;

final class EditorRestBlockNotice
{
    public const NOTICE_ID       = 'contentlatch-audit-blockers';
    public const SAVE_NOTICE_ID  = 'SAVE_POST_NOTICE_ID';
    public const SAVE_NOTICE_IDS = array('SAVE_POST_NOTICE_ID', 'editor-save');

    public static function register(): void
    {
        $page = new self();
        add_action('admin_enqueue_scripts', array($page, 'enqueue'));
    }

    public static function shouldEnqueue(string $hook): bool
    {
        return $hook === 'post.php' || $hook === 'post-new.php';
    }

    public function enqueue(string $hook): void
    {
        if (!self::shouldEnqueue($hook)) {
            return;
        }

        $screen  = function_exists('get_current_screen') ? get_current_screen() : null;
        $isBlock = is_object($screen) && !empty($screen->is_block_editor);
        if (!$isBlock || !function_exists('wp_register_script')) {
            return;
        }

        EditorFieldFocus::enqueueAssets(EditorFieldFocus::sanitizedEditorQuery());

        // acf-input owns validation_complete / validation_failure (ACF 6.0+ Free/Pro).
        // Declaring it as a dependency guarantees those hooks exist before this script runs.
        $deps = array('wp-api-fetch', 'wp-data', 'wp-i18n', 'contentlatch-editor-field');
        if (function_exists('wp_script_is') && wp_script_is('acf-input', 'registered')) {
            $deps[] = 'acf-input';
        }

        wp_register_script(
            'contentlatch-editor-rest-blockers',
            CONTENTLATCH_URL . 'admin/js/editor-rest-blockers.js',
            $deps,
            \ContentLatch\Plugin::VERSION,
            true
        );
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('contentlatch-editor-rest-blockers', 'contentlatch', CONTENTLATCH_DIR . 'languages');
        }
        wp_localize_script(
            'contentlatch-editor-rest-blockers',
            'contentlatchEditorRestBlockers',
            array(
                'errorCode'     => RestSaveValidator::ERROR_CODE,
                'noticeId'      => self::NOTICE_ID,
                'saveNoticeId'  => self::SAVE_NOTICE_ID,
                'saveNoticeIds' => self::SAVE_NOTICE_IDS,
                'i18n'         => array(
                    'blocking' => __('Blocking', 'contentlatch'),
                    /* translators: %d: Number of blocking issues. */
                    'count'    => __('%d blocking issues', 'contentlatch'),
                    'required' => __('This field is required.', 'contentlatch'),
                    /* translators: %s: Field label. */
                    'goToField' => __('Go to field: %s', 'contentlatch'),
                ),
            )
        );
        wp_enqueue_script('contentlatch-editor-rest-blockers');
    }
}
