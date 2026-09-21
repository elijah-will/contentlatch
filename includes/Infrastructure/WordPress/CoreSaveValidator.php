<?php
/**
 * Classic admin save-time validation adapter.
 *
 * Hooked to load-post.php / load-post-new.php so a blocking Core (or mixed)
 * failure can abort before WordPress persists a Classic admin save. Evaluates submitted
 * values through IncomingSaveEvaluator. Classic editpost requests must pass
 * current_user_can('edit_post') and the Classic update-post_{id} nonce before
 * evaluation or wp_die(); unauthorized requests fail closed. Drafts, autosaves,
 * revisions, REST requests, and non-editpost admin posts are not blocked.
 * Gutenberg REST remains RestSaveValidator's responsibility.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

defined('ABSPATH') || exit;

use ContentGuard\Application\EditorNoticePresentation;
use ContentGuard\Application\IncomingSaveEvaluator;
use ContentGuard\Domain\EvaluationResult;
use ContentGuard\Infrastructure\ACF\IntendedPostStatusResolver;
use Throwable;

final class CoreSaveValidator
{
    /**
     * @param callable(): bool|null $isRestRequest
     * @param callable(string $message, string $title, array<string, mixed> $args): void|null $die
     * @param callable(int $postId): bool|null $canEditPost
     * @param callable(string $nonce, string $action): bool|null $verifyNonce
     */
    public function __construct(
        private IncomingSaveEvaluator $incoming,
        private IntendedPostStatusResolver $statusResolver,
        private mixed $isRestRequest = null,
        private mixed $die = null,
        private mixed $canEditPost = null,
        private mixed $verifyNonce = null,
    ) {
    }

    public static function register(IncomingSaveEvaluator $incoming): void
    {
        $validator = new self($incoming, new IntendedPostStatusResolver());
        add_action('load-post.php', array($validator, 'onLoadPost'));
        add_action('load-post-new.php', array($validator, 'onLoadPost'));
    }

    public function onLoadPost(): void
    {
        try {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in isAuthorizedClassicSave() via validate().
            $messages = $this->validate(HttpRequest::unslash(is_array($_POST) ? $_POST : array()));
            if ($messages === null) {
                return;
            }

            $this->abort($messages);
        } catch (Throwable $exception) {
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('ContentGuard Classic save validation failed safely: ' . $exception->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return list<string>|null Blocking messages, or null when the save may proceed.
     */
    public function validate(array $request): ?array
    {
        if ($this->isIgnoredRequest($request)) {
            return null;
        }

        if (!$this->isAuthorizedClassicSave($request)) {
            return null;
        }

        $postType = $this->resolvePostType($request);
        if ($postType === '' || CoreFieldCatalog::isExcludedPostType($postType)) {
            return null;
        }

        if (!$this->statusResolver->isBlockingStatus($this->statusResolver->resolve($request))) {
            return null;
        }

        $corePayload = CoreIncomingPayload::fromRequest($request);
        $acfPayload  = isset($request['acf']) && is_array($request['acf']) ? $request['acf'] : null;
        if ($corePayload === null && $acfPayload === null) {
            return null;
        }

        $evaluation = $this->incoming->evaluate(
            $this->resolvePostId($request),
            $postType,
            $corePayload,
            $acfPayload
        );

        $messages = array();
        foreach ($evaluation->results as $result) {
            if ($result->isFailed()) {
                $messages[] = $this->issueLine($result);
            }
        }

        return $messages === array() ? null : $messages;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function isIgnoredRequest(array $request): bool
    {
        if ($this->servingRestRequest()) {
            return true;
        }

        if (($request['action'] ?? '') !== 'editpost') {
            return true;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return true;
        }

        return false;
    }

    /**
     * Classic editpost must already be allowed to edit the post and present
     * WordPress's update-post_{id} nonce. Fail closed before evaluation.
     *
     * @param array<string, mixed> $request
     */
    private function isAuthorizedClassicSave(array $request): bool
    {
        $postId = $this->resolvePostId($request);

        return $this->userCanEditPost($postId) && $this->classicUpdateNonceIsValid($request, $postId);
    }

    private function userCanEditPost(int $postId): bool
    {
        if (is_callable($this->canEditPost)) {
            return (bool) ($this->canEditPost)($postId);
        }

        return $postId > 0 && function_exists('current_user_can') && current_user_can('edit_post', $postId);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function classicUpdateNonceIsValid(array $request, int $postId): bool
    {
        $nonce = isset($request['_wpnonce']) && is_scalar($request['_wpnonce'])
            ? (string) $request['_wpnonce']
            : '';
        $action = 'update-post_' . $postId;

        if (is_callable($this->verifyNonce)) {
            return (bool) ($this->verifyNonce)($nonce, $action);
        }

        return $postId > 0
            && $nonce !== ''
            && function_exists('wp_verify_nonce')
            && wp_verify_nonce($nonce, $action) !== false;
    }

    private function servingRestRequest(): bool
    {
        if (is_callable($this->isRestRequest)) {
            return (bool) ($this->isRestRequest)();
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        return function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request();
    }

    /**
     * @param array<string, mixed> $request
     */
    private function resolvePostId(array $request): int
    {
        foreach (array('post_ID', 'post_id', '_acf_post_id') as $key) {
            if (isset($request[$key]) && is_numeric($request[$key])) {
                return (int) $request[$key];
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function resolvePostType(array $request): string
    {
        if (isset($request['post_type']) && is_string($request['post_type']) && $request['post_type'] !== '') {
            return $request['post_type'];
        }

        $postId = $this->resolvePostId($request);
        if ($postId > 0 && function_exists('get_post_type')) {
            $type = get_post_type($postId);
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        return '';
    }

    /**
     * @param list<string> $messages
     */
    private function abort(array $messages): void
    {
        $items = array();
        foreach ($messages as $message) {
            $items[] = $this->escape($message);
        }

        $html  = EditorNoticePresentation::noticeHtml(EditorNoticePresentation::SEVERITY_BLOCKING, $items);
        $title = $this->escape(EditorNoticePresentation::title(EditorNoticePresentation::SEVERITY_BLOCKING));
        $args  = array(
            'response'  => 400,
            'back_link' => true,
        );

        if (is_callable($this->die)) {
            ($this->die)($html, $title, $args);

            return;
        }

        if (function_exists('wp_die')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is built from esc_html'd items via noticeHtml; $title is escaped above; $args are wp_die config (response/back_link), not markup.
            wp_die($html, $title, $args);
        }
    }

    private function issueLine(EvaluationResult $result): string
    {
        return EditorNoticePresentation::issueLine(
            (string) ($result->context['field_label'] ?? ''),
            $result->message,
            $result->code
        );
    }

    private function escape(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
