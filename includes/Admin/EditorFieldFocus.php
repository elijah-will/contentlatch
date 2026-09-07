<?php
/**
 * Scrolls the WordPress editor to an ACF field (top-level, Group, Repeater,
 * or Flexible Content child).
 *
 * Used by Audit "Edit content" (URL query) and by clickable editor warnings.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Admin;

use ContentGuard\Application\EditorFieldNavigation;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;

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
     * @param array{layout?: string, displayRow?: int} $extra Transient editor-only navigation hints.
     */
    public static function enqueueAssets(array $request = array(), array $extra = array()): void
    {
        if (!function_exists('wp_register_style') || !function_exists('wp_register_script')) {
            return;
        }

        $fieldKey   = self::autoNavigateFieldKey($request);
        $layout     = isset($extra['layout']) && EditorFieldNavigation::isSafeLayoutName((string) $extra['layout'])
            ? (string) $extra['layout']
            : self::layoutForField($fieldKey, $request);
        $displayRow = EditorFieldNavigation::sanitizeDisplayRow($extra['displayRow'] ?? 0);

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
                'layout'       => $layout,
                'displayRow'   => $displayRow,
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

    /**
     * @param array<string, mixed> $request
     */
    private static function layoutForField(string $fieldKey, array $request): string
    {
        if ($fieldKey === '' || !function_exists('get_post_type')) {
            return '';
        }

        $postId = 0;
        if (isset($request['post']) && is_numeric($request['post'])) {
            $postId = (int) $request['post'];
        }

        if ($postId <= 0) {
            return '';
        }

        $postType = get_post_type($postId);
        if (!is_string($postType) || $postType === '') {
            return '';
        }

        foreach ((new AcfFieldCatalog())->fieldsForPostType($postType) as $field) {
            if ($field->key === $fieldKey && EditorFieldNavigation::isSafeLayoutName($field->layout)) {
                return $field->layout;
            }
        }

        return '';
    }
}
