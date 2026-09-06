<?php
/**
 * Surfaces warning-severity results after a successful publish/private save.
 *
 * acf/validate_save_post cannot show non-blocking warnings: ACF treats any
 * acf_add_validation_error() as a hard save failure.
 *
 * acf/save_post also cannot be the Gutenberg display path. Gutenberg persists
 * ACF values through REST acf_update_value() and never calls acf_save_post().
 * Even when a transient is stored, admin_notices do not run on REST and the
 * block editor does not reload — and hides most PHP notices behind its chrome.
 *
 * V1 display:
 * - Classic: evaluate stored values on the editor request and print admin_notices.
 * - Gutenberg: localize those messages and refresh them via REST after save,
 *   then show wp.data core/notices (not snackbars).
 *
 * Clickable field labels reuse EditorFieldFocus / editor-field.js. Top-level
 * ACF fields only.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

use ContentGuard\Admin\EditorFieldFocus;
use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\EditorFieldNavigation;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\ContentEvaluation;
use ContentGuard\Domain\EvaluationResult;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\WordPress\RulePostType;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

final class SaveWarningNotifier
{
    public const REST_NAMESPACE = 'contentguard/v1';
    public const REST_ROUTE     = '/warnings/(?P<id>\d+)';

    /**
     * @var array<int, list<array{text: string, message: string, label: string, fieldKey: string}>>
     */
    private array $pageCache = array();

    /**
     * @param callable(int $postId): string $postTypeOf
     * @param callable(int $postId): string $postStatusOf
     * @param callable(array<int, string> $messages): void $storeMessages
     * @param callable(): array<int, string> $pullMessages
     * @param callable(string $fieldKey, int $postId): mixed|null $reader
     */
    public function __construct(
        private ContentEvaluator $evaluator,
        private RuleRepositoryInterface $repository,
        private AcfFieldCatalog $catalog,
        private mixed $postTypeOf,
        private mixed $postStatusOf,
        private mixed $storeMessages,
        private mixed $pullMessages,
        private mixed $reader = null,
    ) {
    }

    public static function register(RuleRepositoryInterface $repository): self
    {
        $notifier = new self(
            new ContentEvaluator($repository, RuleEngine::v1()),
            $repository,
            new AcfFieldCatalog(),
            static function (int $postId): string {
                if (!function_exists('get_post_type')) {
                    return '';
                }

                $type = get_post_type($postId);

                return is_string($type) ? $type : '';
            },
            static function (int $postId): string {
                if (!function_exists('get_post_status')) {
                    return '';
                }

                $status = get_post_status($postId);

                return is_string($status) ? $status : '';
            },
            static function (array $messages): void {
            },
            static function (): array {
                return array();
            }
        );

        add_action('admin_enqueue_scripts', array($notifier, 'onAdminEnqueue'));
        add_action('admin_notices', array($notifier, 'onAdminNotices'));
        add_action('rest_api_init', array($notifier, 'registerRestRoute'));

        return $notifier;
    }

    public function registerRestRoute(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'restWarnings'),
                'permission_callback' => static function (WP_REST_Request $request): bool {
                    $id = (int) $request['id'];

                    return $id > 0 && function_exists('current_user_can') && current_user_can('edit_post', $id);
                },
                'args'                => array(
                    'id' => array(
                        'required' => true,
                        'type'     => 'integer',
                    ),
                ),
            )
        );
    }

    public function restWarnings(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->payloadForPost((int) $request['id']), 200);
    }

    /**
     * @return array{messages: list<string>, warnings: list<array{text: string, message: string, label: string, fieldKey: string}>}
     */
    public function payloadForPost(int $postId): array
    {
        return array(
            'messages' => $this->messagesForPost($postId),
            'warnings' => $this->warningsForPost($postId),
        );
    }

    public function onAdminEnqueue(string $hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        $postId   = $this->editorPostId();
        $warnings = $postId > 0 ? $this->warningsForPost($postId) : array();
        $messages = array_values(array_map(
            static fn (array $warning): string => $warning['text'],
            $warnings
        ));
        $screen  = function_exists('get_current_screen') ? get_current_screen() : null;
        $isBlock = is_object($screen) && !empty($screen->is_block_editor);

        if (!$isBlock || !function_exists('wp_register_script')) {
            return;
        }

        EditorFieldFocus::enqueueAssets($_GET);

        wp_register_script(
            'contentguard-editor-warnings',
            CONTENTGUARD_URL . 'admin/js/editor-warnings.js',
            array('wp-api-fetch', 'wp-data', 'contentguard-editor-field'),
            \ContentGuard\Plugin::VERSION,
            true
        );
        wp_localize_script(
            'contentguard-editor-warnings',
            'contentguardEditorWarnings',
            array(
                'postId'   => $postId,
                'messages' => $messages,
                'warnings' => $warnings,
                'restPath' => self::REST_NAMESPACE . '/warnings/' . $postId,
                'i18n'     => array(
                    'warning'   => __('Warning', 'contentguard'),
                    'goToField' => __('Go to field: %s', 'contentguard'),
                ),
            )
        );
        wp_enqueue_script('contentguard-editor-warnings');
    }

    public function onAdminNotices(): void
    {
        if ($this->isBlockEditorScreen()) {
            return;
        }

        $postId = $this->editorPostId();
        if ($postId <= 0) {
            return;
        }

        foreach ($this->warningsForPost($postId) as $warning) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . self::classicNoticeHtml($warning)
                . '</p></div>';
        }
    }

    /**
     * @return list<string>
     */
    public function messagesForPost(int $postId): array
    {
        return array_values(array_map(
            static fn (array $warning): string => $warning['text'],
            $this->warningsForPost($postId)
        ));
    }

    /**
     * @return list<array{text: string, message: string, label: string, fieldKey: string}>
     */
    public function warningsForPost(int $postId): array
    {
        if ($postId <= 0) {
            return array();
        }

        if (array_key_exists($postId, $this->pageCache)) {
            return $this->pageCache[$postId];
        }

        try {
            $this->pageCache[$postId] = $this->evaluateWarnings($postId);
        } catch (Throwable $exception) {
            $this->pageCache[$postId] = array();
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('ContentGuard warning notice failed safely: ' . $exception->getMessage());
            }
        }

        return $this->pageCache[$postId];
    }

    public function notify(int $postId): void
    {
        $messages = $this->messagesForPost($postId);
        if ($messages === array() || !is_callable($this->storeMessages)) {
            return;
        }

        ($this->storeMessages)($messages);
    }

    /**
     * @return list<string>
     */
    public static function warningMessages(ContentEvaluation $evaluation): array
    {
        return array_values(array_map(
            static fn (array $warning): string => $warning['text'],
            self::warningItems($evaluation)
        ));
    }

    /**
     * @return list<array{text: string, message: string, label: string, fieldKey: string}>
     */
    public static function warningItems(ContentEvaluation $evaluation): array
    {
        $items = array();
        $seen  = array();

        foreach ($evaluation->results as $result) {
            if (!$result instanceof EvaluationResult || !$result->isWarning()) {
                continue;
            }

            $item = self::warningItem($result);
            $id   = $item['text'] . "\0" . $item['fieldKey'];
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $items[]   = $item;
        }

        return $items;
    }

    /**
     * @param array{text?: string, message?: string, label?: string, fieldKey?: string} $warning
     */
    public static function isClickableWarning(array $warning): bool
    {
        $label    = trim((string) ($warning['label'] ?? ''));
        $fieldKey = EditorFieldNavigation::navigableFieldKey($warning['fieldKey'] ?? null);

        return $label !== '' && $fieldKey !== '';
    }

    /**
     * Human-readable notice text. Never the structured warning object.
     *
     * @param array{text?: string, message?: string, label?: string, fieldKey?: string} $warning
     */
    public static function displayText(array $warning): string
    {
        $label   = trim((string) ($warning['label'] ?? ''));
        $message = trim((string) ($warning['message'] ?? ''));
        $text    = trim((string) ($warning['text'] ?? ''));
        $prefix  = function_exists('__') ? __('Warning', 'contentguard') : 'Warning';

        if ($label !== '' && $message !== '') {
            return $prefix . ': ' . $label . ' — ' . $message;
        }

        if ($text !== '') {
            return $text;
        }

        return $message !== '' ? $message : $label;
    }

    /**
     * @param array{text?: string, message?: string, label?: string, fieldKey?: string} $warning
     */
    public static function classicNoticeHtml(array $warning): string
    {
        $label    = trim((string) ($warning['label'] ?? ''));
        $message  = (string) ($warning['message'] ?? '');
        $fieldKey = EditorFieldNavigation::navigableFieldKey($warning['fieldKey'] ?? null);

        if ($fieldKey === '' || $label === '') {
            return self::escapeHtml(self::displayText($warning));
        }

        $warningLabel = function_exists('__') ? __('Warning', 'contentguard') : 'Warning';

        return self::escapeHtml($warningLabel) . ': <button type="button" class="contentguard-warning-field" data-contentguard-field="'
            . self::escapeAttr($fieldKey)
            . '" aria-label="' . self::escapeAttr(EditorFieldNavigation::goToFieldAria($label)) . '">'
            . self::escapeHtml($label)
            . '</button> — '
            . self::escapeHtml($message !== '' ? $message : 'Content warning.');
    }

    public static function isPublishedStatus(string $status): bool
    {
        return $status === 'publish' || $status === 'private';
    }

    /**
     * @return list<array{text: string, message: string, label: string, fieldKey: string}>
     */
    private function evaluateWarnings(int $postId): array
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return array();
        }

        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($postId)) {
            return array();
        }

        if (function_exists('wp_is_post_revision') && wp_is_post_revision($postId)) {
            return array();
        }

        $postType = is_callable($this->postTypeOf) ? (string) ($this->postTypeOf)($postId) : '';
        if ($postType === '' || $postType === RulePostType::POST_TYPE) {
            return array();
        }

        $status = is_callable($this->postStatusOf) ? (string) ($this->postStatusOf)($postId) : '';
        if (!self::isPublishedStatus($status)) {
            return array();
        }

        $rules = $this->repository->findActiveForPostType($postType);
        if ($rules === array()) {
            return array();
        }

        $catalogTypes = $this->catalog->fieldTypesForPostType($postType);
        $fieldTypes   = array_intersect_key($catalogTypes, $this->referencedFieldKeys($rules));

        $evaluation = $this->evaluator->evaluate(
            $postId,
            $postType,
            new AcfStoredValueProvider($postId, new AcfValueNormalizer(), $fieldTypes, $this->reader)
        );

        return self::warningItems($evaluation);
    }

    /**
     * @return array{text: string, message: string, label: string, fieldKey: string}
     */
    private static function warningItem(EvaluationResult $result): array
    {
        $label   = trim((string) ($result->context['field_label'] ?? ''));
        $message = $result->message !== '' ? $result->message : 'Content warning.';

        return array(
            'text'     => self::formatWarning($result),
            'message'  => $message,
            'label'    => $label,
            'fieldKey' => EditorFieldNavigation::navigableFieldKey($result->fieldId),
        );
    }

    private function editorPostId(): int
    {
        if (isset($_GET['post']) && is_numeric($_GET['post'])) {
            return (int) $_GET['post'];
        }

        if (isset($GLOBALS['post']) && is_object($GLOBALS['post']) && isset($GLOBALS['post']->ID)) {
            return (int) $GLOBALS['post']->ID;
        }

        return 0;
    }

    private function isBlockEditorScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        return is_object($screen) && !empty($screen->is_block_editor);
    }

    /**
     * @param Rule[] $rules
     * @return array<string, true>
     */
    private function referencedFieldKeys(array $rules): array
    {
        $keys = array();

        foreach ($rules as $rule) {
            foreach ($rule->conditions as $condition) {
                $keys[$condition->field->key] = true;
            }
            foreach ($rule->validations as $validation) {
                $keys[$validation->field->key] = true;
            }
        }

        return $keys;
    }

    private static function formatWarning(EvaluationResult $result): string
    {
        $label   = (string) ($result->context['field_label'] ?? '');
        $message = $result->message !== '' ? $result->message : 'Content warning.';

        if ($label !== '' && !str_contains($message, $label)) {
            return $label . ': ' . $message;
        }

        return $message;
    }

    private static function escapeHtml(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function escapeAttr(string $value): string
    {
        if (function_exists('esc_attr')) {
            return esc_attr($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
