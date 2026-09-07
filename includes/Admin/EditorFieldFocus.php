<?php
/**
 * Scrolls the WordPress editor to an ACF field (top-level or Group child).
 *
 * Used by Audit "Edit content" (URL query) and by clickable editor warnings.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

use ContentGuard\Application\EditorFieldNavigation;

final class EditorFieldFocus
{
    public static function register(): void
    {
        $page = new self();
        add_action('admin_enqueue_scripts', array($page, 'enqueue'));
    }

    public static function shouldEnqueue(string $hook, array $request = array()): bool
    {
        unset($request);

        return $hook === 'post.php' || $hook === 'post-new.php';
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function autoNavigateFieldKey(array $request): string
    {
        return EditorFieldNavigation::requestedFieldKey($request);
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function enqueueAssets(array $request = array()): void
    {
        if (!function_exists('wp_register_style') || !function_exists('wp_register_script')) {
            return;
        }

        $fieldKey = self::autoNavigateFieldKey($request);

        wp_register_style(
            'contentguard-editor-field',
            CONTENTGUARD_URL . 'admin/css/editor.css',
            array(),
            \ContentGuard\Plugin::VERSION
        );
        wp_enqueue_style('contentguard-editor-field');

        wp_register_script(
            'contentguard-editor-field',
            CONTENTGUARD_URL . 'admin/js/editor-field.js',
            array(),
            \ContentGuard\Plugin::VERSION,
            true
        );
        wp_localize_script(
            'contentguard-editor-field',
            'contentguardEditorField',
            array(
                'fieldKey'     => $fieldKey,
                'autoNavigate' => $fieldKey !== '',
                'i18n'         => array(
                    'navigated' => __('Moved to the field that needs attention.', 'contentguard'),
                ),
            )
        );
        wp_enqueue_script('contentguard-editor-field');
    }

    public function enqueue(string $hook): void
    {
        if (!self::shouldEnqueue($hook)) {
            return;
        }

        self::enqueueAssets($_GET);
    }
}
