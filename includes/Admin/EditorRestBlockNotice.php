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

        wp_register_script(
            'contentguard-editor-rest-blockers',
            CONTENTGUARD_URL . 'admin/js/editor-rest-blockers.js',
            array('wp-api-fetch', 'wp-data'),
            \ContentGuard\Plugin::VERSION,
            true
        );
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
                    'count'    => __('%d blocking issues', 'contentguard'),
                    'required' => __('This field is required.', 'contentguard'),
                ),
            )
        );
        wp_enqueue_script('contentguard-editor-rest-blockers');
    }
}
