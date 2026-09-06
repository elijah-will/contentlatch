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
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

use ContentGuard\Application\ContentEvaluator;
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
     * @var array<int, list<string>>
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
     * @return array{messages: list<string>}
     */
    public function payloadForPost(int $postId): array
    {
        return array('messages' => $this->messagesForPost($postId));
    }

    public function onAdminEnqueue(string $hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        $postId = $this->editorPostId();
        $messages = $postId > 0 ? $this->messagesForPost($postId) : array();
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $isBlock = is_object($screen) && !empty($screen->is_block_editor);

        if (!$isBlock || !function_exists('wp_register_script')) {
            return;
        }

        wp_register_script(
            'contentguard-editor-warnings',
            CONTENTGUARD_URL . 'admin/js/editor-warnings.js',
            array('wp-api-fetch', 'wp-data'),
            \ContentGuard\Plugin::VERSION,
            true
        );
        wp_localize_script(
            'contentguard-editor-warnings',
            'contentguardEditorWarnings',
            array(
                'postId'   => $postId,
                'messages' => $messages,
                'restPath' => self::REST_NAMESPACE . '/warnings/' . $postId,
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

        foreach ($this->messagesForPost($postId) as $message) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . esc_html($message)
                . '</p></div>';
        }
    }

    /**
     * @return list<string>
     */
    public function messagesForPost(int $postId): array
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
        $messages = array();

        foreach ($evaluation->results as $result) {
            if (!$result instanceof EvaluationResult || !$result->isWarning()) {
                continue;
            }

            $messages[] = self::formatWarning($result);
        }

        return array_values(array_unique($messages));
    }

    public static function isPublishedStatus(string $status): bool
    {
        return $status === 'publish' || $status === 'private';
    }

    /**
     * @return list<string>
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

        return self::warningMessages($evaluation);
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
}
