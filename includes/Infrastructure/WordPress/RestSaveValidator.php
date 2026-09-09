<?php
/**
 * WordPress REST save-time validation adapter.
 *
 * Hooked to rest_pre_insert_{$post_type}. Evaluates submitted Core values
 * (and ACF values when present on the request) through IncomingSaveEvaluator.
 * Blocking failures return WP_Error with HTTP 400. Warnings never reject
 * the request. Drafts, autosaves, and revisions are not blocked.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

use ContentGuard\Application\IncomingSaveEvaluator;
use ContentGuard\Domain\EvaluationResult;
use ContentGuard\Infrastructure\ACF\IntendedPostStatusResolver;
use Throwable;
use WP_Error;

final class RestSaveValidator
{
    public const ERROR_CODE = 'contentguard_validation_failed';

    public function __construct(
        private IncomingSaveEvaluator $incoming,
        private IntendedPostStatusResolver $statusResolver,
    ) {
    }

    public static function register(IncomingSaveEvaluator $incoming): void
    {
        $validator = new self($incoming, new IntendedPostStatusResolver());
        add_action('rest_api_init', array($validator, 'registerFilters'), 99);
    }

    public function registerFilters(): void
    {
        if (!function_exists('get_post_types')) {
            return;
        }

        $types = get_post_types(array('show_in_rest' => true), 'names');
        if (!is_array($types)) {
            return;
        }

        foreach ($types as $type) {
            if (!is_string($type) || $type === '' || CoreFieldCatalog::isExcludedPostType($type)) {
                continue;
            }

            add_filter(
                'rest_pre_insert_' . $type,
                function (mixed $preparedPost, mixed $request) use ($type): mixed {
                    return $this->onPreInsert($preparedPost, $request, $type);
                },
                10,
                2
            );
        }
    }

    public function onPreInsert(mixed $preparedPost, mixed $request, string $registeredType = ''): mixed
    {
        try {
            return $this->validate($preparedPost, $request, $registeredType);
        } catch (Throwable $exception) {
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('ContentGuard REST save validation failed safely: ' . $exception->getMessage());
            }

            return $preparedPost;
        }
    }

    public function validate(mixed $preparedPost, mixed $request, string $registeredType = ''): mixed
    {
        if (class_exists(WP_Error::class) && $preparedPost instanceof WP_Error) {
            return $preparedPost;
        }

        if (!is_object($preparedPost) || $this->isIgnoredRequest($preparedPost, $request)) {
            return $preparedPost;
        }

        $postType = $this->resolvePostType($preparedPost, $request, $registeredType);
        if ($postType === '' || CoreFieldCatalog::isExcludedPostType($postType)) {
            return $preparedPost;
        }

        $status = $this->resolveStatus($preparedPost, $request);
        if (!$this->statusResolver->isBlockingStatus($status)) {
            return $preparedPost;
        }

        $postId = isset($preparedPost->ID) && is_numeric($preparedPost->ID)
            ? (int) $preparedPost->ID
            : 0;

        $acfPayload = CoreIncomingPayload::requestValue($request, 'acf');
        $acfPayload = is_array($acfPayload) ? $acfPayload : null;

        $evaluation = $this->incoming->evaluate(
            $postId,
            $postType,
            CoreIncomingPayload::fromPreparedPost($preparedPost, $request),
            $acfPayload
        );

        $failures = array();
        foreach ($evaluation->results as $result) {
            if ($result->isFailed()) {
                $failures[] = $result;
            }
        }

        if ($failures === array()) {
            return $preparedPost;
        }

        return $this->toWpError($failures);
    }

    private function isIgnoredRequest(object $preparedPost, mixed $request): bool
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return true;
        }

        $route = CoreIncomingPayload::requestRoute($request);
        if ($route !== '' && str_contains($route, 'autosaves')) {
            return true;
        }

        $postType = $this->resolvePostType($preparedPost, $request);

        return $postType === 'revision';
    }

    private function resolvePostType(object $preparedPost, mixed $request, string $registeredType = ''): string
    {
        if (isset($preparedPost->post_type) && is_string($preparedPost->post_type) && $preparedPost->post_type !== '') {
            return $preparedPost->post_type;
        }

        foreach (array('type', 'post_type') as $key) {
            $type = CoreIncomingPayload::requestValue($request, $key);
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        $id = isset($preparedPost->ID) && is_numeric($preparedPost->ID) ? (int) $preparedPost->ID : 0;
        if ($id > 0 && function_exists('get_post_type')) {
            $stored = get_post_type($id);
            if (is_string($stored) && $stored !== '') {
                return $stored;
            }
        }

        if ($registeredType !== '') {
            return $registeredType;
        }

        if (function_exists('current_filter')) {
            $filter = (string) current_filter();
            $prefix = 'rest_pre_insert_';
            if (str_starts_with($filter, $prefix)) {
                $fromFilter = substr($filter, strlen($prefix));
                if ($fromFilter !== '') {
                    return $fromFilter;
                }
            }
        }

        return '';
    }

    private function resolveStatus(object $preparedPost, mixed $request): string
    {
        if (isset($preparedPost->post_status) && is_scalar($preparedPost->post_status) && (string) $preparedPost->post_status !== '') {
            return (string) $preparedPost->post_status;
        }

        $status = CoreIncomingPayload::requestValue($request, 'status');
        if (is_scalar($status) && (string) $status !== '') {
            return (string) $status;
        }

        $id = isset($preparedPost->ID) && is_numeric($preparedPost->ID) ? (int) $preparedPost->ID : 0;
        if ($id > 0 && function_exists('get_post_status')) {
            $stored = get_post_status($id);
            if (is_string($stored) && $stored !== '') {
                return $stored;
            }
        }

        return 'draft';
    }

    /**
     * @param EvaluationResult[] $failures
     */
    private function toWpError(array $failures): WP_Error
    {
        $messages = array();
        $items    = array();
        foreach ($failures as $result) {
            $message = $this->errorMessage($result);
            $messages[] = $message;
            $items[]    = array(
                'message' => $message,
                'field'   => $result->fieldId,
                'code'    => $result->code,
                'rule_id' => $result->ruleId,
                'label'   => (string) ($result->context['field_label'] ?? ''),
            );
        }

        $summary = implode(' ', $messages);

        return new WP_Error(
            self::ERROR_CODE,
            $summary,
            array(
                'status'   => 400,
                'messages' => $messages,
                'failures' => $items,
            )
        );
    }

    private function errorMessage(EvaluationResult $result): string
    {
        $message = $result->message;
        $label   = (string) ($result->context['field_label'] ?? '');

        if ($result->code === 'required' && $message === 'This field is required.' && $label !== '') {
            return sprintf('%s is required.', $label);
        }

        if ($message !== '') {
            return $message;
        }

        return $label !== '' ? sprintf('%s is invalid.', $label) : 'ContentGuard validation failed.';
    }
}
