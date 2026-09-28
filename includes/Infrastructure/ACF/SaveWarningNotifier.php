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
 * - Classic: evaluate stored values on the individual post editor (post.php)
 *   and print admin_notices there only. List screens and other admin pages
 *   must not show these notices.
 * - Gutenberg: localize those messages and refresh them via REST after save,
 *   then show wp.data core/notices (not snackbars).
 *
 * Clickable field labels reuse EditorFieldFocus / editor-field.js. Top-level
 * ACF fields and Group children (leaf field keys).
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\ACF;

defined('ABSPATH') || exit;

use ContentLatch\Admin\EditorFieldFocus;
use ContentLatch\Application\ContentEvaluator;
use ContentLatch\Application\EditorCoreNavigation;
use ContentLatch\Application\EditorFieldNavigation;
use ContentLatch\Application\EditorNoticePresentation;
use ContentLatch\Application\DomainMessages;
use ContentLatch\Application\Integration\FieldCatalog;
use ContentLatch\Application\RuleRepositoryInterface;
use ContentLatch\Domain\ContentEvaluation;
use ContentLatch\Domain\Contracts\FieldValueProviderInterface;
use ContentLatch\Domain\EvaluationResult;
use ContentLatch\Domain\Rule;
use ContentLatch\Domain\RuleEngine;
use ContentLatch\Infrastructure\WordPress\RulePostType;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

final class SaveWarningNotifier
{
    public const REST_NAMESPACE = 'contentlatch/v1';
    public const REST_ROUTE     = '/warnings/(?P<id>\d+)';

    /**
     * @var array<int, list<array{text: string, message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>>
     */
    private array $pageCache = array();

    /**
     * @param callable(int $postId): string $postTypeOf
     * @param callable(int $postId): string $postStatusOf
     * @param callable(array<int, string> $messages): void $storeMessages
     * @param callable(): array<int, string> $pullMessages
     * @param callable(string $fieldKey, int $postId): mixed|null $reader
     * @param callable(): object|null|null $screenOf Optional get_current_screen() double.
     * @param FieldCatalog|null $fieldCatalog Composite catalog so Core warnings use stored Core values.
     * @param callable(int $postId, string $postType, array<string, string> $fieldTypes): FieldValueProviderInterface|null $providerFactory
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
        private mixed $screenOf = null,
        private mixed $fieldCatalog = null,
        private mixed $providerFactory = null,
    ) {
    }

    /**
     * @param callable(int $postId, string $postType, array<string, string> $fieldTypes): FieldValueProviderInterface|null $providerFactory
     */
    public static function register(
        RuleRepositoryInterface $repository,
        ?FieldCatalog $fieldCatalog = null,
        mixed $providerFactory = null,
    ): self {
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
            },
            null,
            null,
            $fieldCatalog,
            $providerFactory
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
     * @return array{messages: list<string>, warnings: list<array{text: string, message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>, html: string, text: string}
     */
    public function payloadForPost(int $postId): array
    {
        $warnings = $this->warningsForPost($postId);

        return array(
            'messages' => array_values(array_map(
                static fn (array $warning): string => $warning['text'],
                $warnings
            )),
            'warnings' => $warnings,
            'html'     => self::noticeHtml($warnings, EditorCoreNavigation::SURFACE_GUTENBERG),
            'text'     => self::noticeText($warnings),
        );
    }

    public static function shouldEnqueue(string $hook): bool
    {
        return $hook === 'post.php' || $hook === 'post-new.php';
    }

    public function onAdminEnqueue(string $hook): void
    {
        if (!self::shouldEnqueue($hook)) {
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

        // Only republish contentlatchEditorField when there are warnings to
        // navigate. An empty extras localize overwrites Audit arrival state.
        if ($warnings !== array()) {
            EditorFieldFocus::enqueueAssets(
                EditorFieldFocus::sanitizedEditorQuery(),
                self::navigationExtras($warnings)
            );
        }

        wp_register_script(
            'contentlatch-editor-warnings',
            CONTENTLATCH_URL . 'admin/js/editor-warnings.js',
            array('wp-api-fetch', 'wp-data', 'wp-i18n', 'contentlatch-editor-field'),
            \ContentLatch\Plugin::VERSION,
            true
        );
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('contentlatch-editor-warnings', 'contentlatch', CONTENTLATCH_DIR . 'languages');
        }
        wp_localize_script(
            'contentlatch-editor-warnings',
            'contentlatchEditorWarnings',
            array(
                'postId'   => $postId,
                'messages' => $messages,
                'warnings' => $warnings,
                'restPath' => self::REST_NAMESPACE . '/warnings/' . $postId,
                'html'     => self::noticeHtml($warnings, EditorCoreNavigation::SURFACE_GUTENBERG),
                'text'     => self::noticeText($warnings),
                'i18n'     => array(
                    'warning'   => __('Warning', 'contentlatch'),
                    /* translators: %s: Field label. */
                    'goToField' => __('Go to field: %s', 'contentlatch'),
                ),
            )
        );
        wp_enqueue_script('contentlatch-editor-warnings');
    }

    public function shouldRenderClassicNotices(): bool
    {
        return $this->isIndividualPostEditorScreen() && !$this->isBlockEditorScreen();
    }

    public function onAdminNotices(): void
    {
        if (!$this->shouldRenderClassicNotices()) {
            return;
        }

        $postId = $this->editorPostId();
        if ($postId <= 0) {
            return;
        }

        $html = self::classicNoticeHtml($this->warningsForPost($postId));
        if ($html === '') {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress branch uses wp_kses() with allowedNoticeHtml(); fallback is for non-WordPress/test environments. $html items are already escaped at construction.
        echo EditorNoticePresentation::kses($html);
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
     * @return list<array{text: string, message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>
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
                error_log('ContentLatch warning notice failed safely: ' . $exception->getMessage());
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
     * @return list<array{text: string, message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>
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
                $items[$seen[$id]] = EditorFieldNavigation::mergeRowTargets($items[$seen[$id]], $item);
                continue;
            }

            $seen[$id] = count($items);
            $items[]   = $item;
        }

        return $items;
    }

    /**
     * @param array{text?: string, message?: string, label?: string, fieldKey?: string} $warning
     */
    public static function isClickableWarning(array $warning, ?string $surface = null): bool
    {
        $surface ??= EditorCoreNavigation::currentSurface();

        return EditorFieldNavigation::isClickableTarget(
            (string) ($warning['fieldKey'] ?? ''),
            (string) ($warning['label'] ?? ''),
            $surface
        );
    }

    /**
     * Human-readable issue line. Never the structured warning object.
     *
     * @param array{text?: string, message?: string, label?: string, fieldKey?: string} $warning
     */
    public static function displayText(array $warning): string
    {
        $label   = trim((string) ($warning['label'] ?? ''));
        $message = trim((string) ($warning['message'] ?? ''));
        $text    = trim((string) ($warning['text'] ?? ''));
        $line    = EditorNoticePresentation::issueText($label, $message);

        return $line !== '' ? $line : $text;
    }

    /**
     * @param array{text?: string, message?: string, label?: string, fieldKey?: string}|list<array{text?: string, message?: string, label?: string, fieldKey?: string}> $warningOrWarnings
     */
    public static function classicNoticeHtml(array $warningOrWarnings): string
    {
        $inner = self::noticeHtml(
            self::normalizeWarningList($warningOrWarnings),
            EditorCoreNavigation::SURFACE_CLASSIC
        );
        if ($inner === '') {
            return '';
        }

        return '<div class="notice notice-warning is-dismissible contentlatch-editor-warnings-notice">' . $inner . '</div>';
    }

    /**
     * @param list<array{text?: string, message?: string, label?: string, fieldKey?: string, layout?: string, affectedRows?: list<int>}> $warnings
     */
    public static function noticeHtml(array $warnings, ?string $surface = null): string
    {
        $surface = EditorCoreNavigation::normalizeSurface(
            $surface ?? EditorCoreNavigation::currentSurface()
        );
        $items   = array();
        foreach ($warnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }

            $items[] = self::itemHtml($warning, $surface);
        }

        return EditorNoticePresentation::noticeHtml(EditorNoticePresentation::SEVERITY_WARNING, $items);
    }

    /**
     * @param list<array{text?: string, message?: string, label?: string, fieldKey?: string}> $warnings
     */
    public static function noticeText(array $warnings): string
    {
        $lines = array();
        foreach ($warnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }

            $line = self::displayText($warning);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return EditorNoticePresentation::noticeText(EditorNoticePresentation::SEVERITY_WARNING, $lines);
    }

    /**
     * @param array{text?: string, message?: string, label?: string, fieldKey?: string} $warning
     */
    public static function itemHtml(array $warning, ?string $surface = null): string
    {
        $surface ??= EditorCoreNavigation::currentSurface();

        return EditorFieldNavigation::clickableIssueHtml(
            (string) ($warning['fieldKey'] ?? ''),
            (string) ($warning['label'] ?? ''),
            (string) ($warning['message'] ?? ''),
            __('Content warning.', 'contentlatch'),
            $surface,
            EditorFieldNavigation::layoutFromItem($warning),
            EditorFieldNavigation::affectedRowsFromItem($warning),
            EditorFieldNavigation::repeaterPathFromItem($warning),
            EditorFieldNavigation::isRepeaterPathBlocked($warning)
        );
    }

    /**
     * @param array<string, mixed> $warningOrWarnings
     * @return list<array<string, mixed>>
     */
    private static function normalizeWarningList(array $warningOrWarnings): array
    {
        if ($warningOrWarnings === array()) {
            return array();
        }

        if (array_is_list($warningOrWarnings) && isset($warningOrWarnings[0]) && is_array($warningOrWarnings[0])) {
            return $warningOrWarnings;
        }

        return array($warningOrWarnings);
    }

    public static function isPublishedStatus(string $status): bool
    {
        return $status === 'publish' || $status === 'private';
    }

    /**
     * @return list<array{text: string, message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}>
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

        $typesCatalog = $this->fieldCatalog instanceof FieldCatalog ? $this->fieldCatalog : $this->catalog;
        $catalogTypes = $typesCatalog->fieldTypesForPostType($postType);
        $fieldTypes   = array_intersect_key($catalogTypes, $this->referencedFieldKeys($rules));

        $evaluation = $this->evaluator->evaluate(
            $postId,
            $postType,
            $this->provider($postId, $postType, $fieldTypes)
        );

        return self::warningItems($evaluation);
    }

    /**
     * @param array<string, string> $fieldTypes
     */
    private function provider(int $postId, string $postType, array $fieldTypes): FieldValueProviderInterface
    {
        if (is_callable($this->providerFactory)) {
            $provider = ($this->providerFactory)($postId, $postType, $fieldTypes);
            if ($provider instanceof FieldValueProviderInterface) {
                return $provider;
            }
        }

        $maps = $this->catalog->nestedResolutionMaps($postType, $fieldTypes);

        return new AcfStoredValueProvider(
            $postId,
            new AcfValueNormalizer(),
            $fieldTypes,
            $this->reader,
            $maps['paths'],
            $maps['names'],
            $maps['repeater_keys'],
            $maps['flex_keys'] ?? array(),
            $maps['layouts'] ?? array(),
            $maps['clone_keys'] ?? array(),
            $maps['repeater_chains'] ?? array()
        );
    }

    /**
     * @return array{text: string, message: string, label: string, fieldKey: string, layout?: string, affectedRows?: list<int>}
     */
    private static function warningItem(EvaluationResult $result): array
    {
        $label   = trim((string) ($result->context['field_label'] ?? ''));
        $message = $result->message !== ''
            ? DomainMessages::present($result->message)
            : __('Content warning.', 'contentlatch');

        return EditorFieldNavigation::withEvaluationRowTargets(
            array(
                'text'     => self::formatWarning($result),
                'message'  => $message,
                'label'    => $label,
                'fieldKey' => EditorFieldNavigation::navigationId($result->fieldId),
            ),
            $result->context
        );
    }

    /**
     * @param list<array{text?: string, message?: string, label?: string, fieldKey?: string, layout?: string, affectedRows?: list<int>}> $warnings
     * @return array{layout?: string, displayRow?: int}
     */
    private static function navigationExtras(array $warnings): array
    {
        if (count($warnings) !== 1) {
            return array();
        }

        $extra  = array();
        $layout = EditorFieldNavigation::layoutFromItem($warnings[0]);
        if ($layout !== '') {
            $extra['layout'] = $layout;
        }

        $rows = EditorFieldNavigation::affectedRowsFromItem($warnings[0]);
        if (count($rows) === 1) {
            $extra['displayRow'] = $rows[0];
        }

        $path = EditorFieldNavigation::repeaterPathFromItem($warnings[0]);
        if ($path !== array()) {
            $extra['repeaterPath'] = $path;
        }

        return $extra;
    }

    private function editorPostId(): int
    {
        if (isset($_GET['post'])) {
            return absint(wp_unslash((string) $_GET['post']));
        }

        if (isset($_GET['post_ID'])) {
            return absint(wp_unslash((string) $_GET['post_ID']));
        }

        if (!$this->isIndividualPostEditorScreen()) {
            return 0;
        }

        if (isset($GLOBALS['post']) && is_object($GLOBALS['post']) && isset($GLOBALS['post']->ID)) {
            return (int) $GLOBALS['post']->ID;
        }

        return 0;
    }

    private function isIndividualPostEditorScreen(): bool
    {
        $screen = $this->currentScreen();
        if ($screen !== null) {
            return ($screen->base ?? '') === 'post';
        }

        $page = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';

        return $page === 'post.php' || $page === 'post-new.php';
    }

    private function currentScreen(): ?object
    {
        if (is_callable($this->screenOf)) {
            $screen = ($this->screenOf)();

            return is_object($screen) ? $screen : null;
        }

        if (!function_exists('get_current_screen')) {
            return null;
        }

        $screen = get_current_screen();

        return is_object($screen) ? $screen : null;
    }

    private function isBlockEditorScreen(): bool
    {
        $screen = $this->currentScreen();

        return $screen !== null && !empty($screen->is_block_editor);
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
                $keys[$condition->field->resolutionId()] = true;
            }
            foreach ($rule->validations as $validation) {
                $keys[$validation->field->resolutionId()] = true;
            }
        }

        return $keys;
    }

    private static function formatWarning(EvaluationResult $result): string
    {
        $label   = (string) ($result->context['field_label'] ?? '');
        $message = $result->message !== ''
            ? DomainMessages::present($result->message)
            : __('Content warning.', 'contentlatch');

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
