<?php
/**
 * Gutenberg presentation for ContentGuard REST save blocking errors.
 *
 * Does not change REST validation. HTTP 400 with
 * contentguard_validation_failed remains the source of truth. This class
 * only enqueues the editor script that replaces Gutenberg's generic save
 * notice with the existing ContentGuard blocking notice chrome.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

defined('ABSPATH') || exit;

use ContentGuard\Infrastructure\WordPress\RestSaveValidator;

final class EditorRestBlockNotice
{
    public const NOTICE_ID       = 'contentguard-audit-blockers';
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
        $deps = array('wp-api-fetch', 'wp-data', 'wp-i18n', 'contentguard-editor-field');
        if (function_exists('wp_script_is') && wp_script_is('acf-input', 'registered')) {
            $deps[] = 'acf-input';
        }

        wp_register_script(
            'contentguard-editor-rest-blockers',
            CONTENTGUARD_URL . 'admin/js/editor-rest-blockers.js',
            $deps,
            \ContentGuard\Plugin::VERSION,
            true
        );
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('contentguard-editor-rest-blockers', 'contentguard', CONTENTGUARD_DIR . 'languages');
        }
        wp_localize_script(
            'contentguard-editor-rest-blockers',
            'contentguardEditorRestBlockers',
            array(
                'errorCode'     => RestSaveValidator::ERROR_CODE,
                'noticeId'      => self::NOTICE_ID,
                'saveNoticeId'  => self::SAVE_NOTICE_ID,
                'saveNoticeIds' => self::SAVE_NOTICE_IDS,
                'i18n'         => array(
                    'blocking' => __('Blocking', 'contentguard'),
                    /* translators: %d: Number of blocking issues. */
                    'count'    => __('%d blocking issues', 'contentguard'),
                    'required' => __('This field is required.', 'contentguard'),
                    /* translators: %s: Field label. */
                    'goToField' => __('Go to field: %s', 'contentguard'),
                ),
            )
        );
        wp_enqueue_script('contentguard-editor-rest-blockers');
    }
}
