<?php
/**
 * Scrolls the WordPress editor to an ACF field or an allowlisted Core field.
 *
 * Used by Audit "Edit content" (URL query) and by clickable editor notices.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Admin;

defined('ABSPATH') || exit;

use ContentLatch\Application\EditorCoreNavigation;
use ContentLatch\Application\EditorFieldNavigation;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;

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
     * @param array{layout?: string, displayRow?: int, repeaterPath?: list<array{repeater: string, display_row: int}>} $extra Transient editor-only navigation hints.
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
        $repeaterPath = EditorFieldNavigation::sanitizeRepeaterPath($extra['repeaterPath'] ?? array());

        wp_register_style(
            'contentlatch-editor-field',
            CONTENTLATCH_URL . 'admin/css/editor.css',
            array(),
            \ContentLatch\Plugin::VERSION
        );
        wp_enqueue_style('contentlatch-editor-field');

        wp_register_script(
            'contentlatch-editor-field',
            CONTENTLATCH_URL . 'admin/js/editor-field.js',
            array(),
            \ContentLatch\Plugin::VERSION,
            true
        );
        $screen  = function_exists('get_current_screen') ? get_current_screen() : null;
        $surface = is_object($screen) && !empty($screen->is_block_editor)
            ? EditorCoreNavigation::SURFACE_GUTENBERG
            : EditorCoreNavigation::SURFACE_CLASSIC;

        wp_localize_script(
            'contentlatch-editor-field',
            'contentlatchEditorField',
            array(
                'fieldKey'     => $fieldKey,
                'layout'       => $layout,
                'displayRow'   => $displayRow,
                'repeaterPath' => $repeaterPath,
                'autoNavigate' => $fieldKey !== '',
                'core'         => array_merge(
                    EditorCoreNavigation::clientConfig(),
                    array('surface' => $surface)
                ),
                'i18n'         => array(
                    'navigated' => __('Moved to the field that needs attention.', 'contentlatch'),
                ),
            )
        );
        wp_enqueue_script('contentlatch-editor-field');
    }

    public function enqueue(string $hook): void
    {
        if (!self::shouldEnqueue($hook)) {
            return;
        }

        self::enqueueAssets(self::sanitizedEditorQuery());
    }

    /**
     * Read-only editor query used to focus a field. Not a mutation.
     *
     * @return array<string, int|string>
     */
    public static function sanitizedEditorQuery(): array
    {
        $request  = array();
        $fieldArg = EditorFieldNavigation::QUERY_ARG;
        $runArg   = EditorFieldNavigation::AUDIT_RUN_ARG;

        if (isset($_GET[$fieldArg])) {
            $field = sanitize_text_field(wp_unslash((string) $_GET[$fieldArg]));
            if (EditorFieldNavigation::isQueryTarget($field)) {
                $request[$fieldArg] = $field;
            }
        }

        if (isset($_GET[$runArg])) {
            $request[$runArg] = sanitize_text_field(wp_unslash((string) $_GET[$runArg]));
        }

        if (isset($_GET['post'])) {
            $request['post'] = absint(wp_unslash((string) $_GET['post']));
        }

        return $request;
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
            if (
                $field->resolutionId() === $fieldKey
                && EditorFieldNavigation::isSafeLayoutName($field->layout)
            ) {
                return $field->layout;
            }
        }

        return '';
    }
}
