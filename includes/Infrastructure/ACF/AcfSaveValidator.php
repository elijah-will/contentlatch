<?php
/**
 * ACF save-time validation adapter.
 *
 * Hooked to acf/validate_save_post. Reads $_POST only at this boundary,
 * evaluates Core + ACF incoming values through IncomingSaveEvaluator, and
 * reports blocking failures with acf_add_validation_error(). Warning
 * results are ignored here because ACF treats every validation error as a
 * hard save failure. Post-save warning notices are handled by
 * SaveWarningNotifier.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

use ContentGuard\Application\EditorNoticePresentation;
use ContentGuard\Application\IncomingSaveEvaluator;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\EvaluationResult;
use ContentGuard\Infrastructure\WordPress\CoreFieldCatalog;
use ContentGuard\Infrastructure\WordPress\CoreIncomingPayload;
use Throwable;

final class AcfSaveValidator
{
    /**
     * @param callable(string $input, string $message): void $addError
     */
    public function __construct(
        private RuleRepositoryInterface $repository,
        private AcfFieldCatalog $catalog,
        private IntendedPostStatusResolver $statusResolver,
        private mixed $addError,
        private IncomingSaveEvaluator $incoming,
    ) {
    }

    public static function register(
        RuleRepositoryInterface $repository,
        IncomingSaveEvaluator $incoming,
    ): void {
        $validator = new self(
            $repository,
            new AcfFieldCatalog(),
            new IntendedPostStatusResolver(),
            static function (string $input, string $message): void {
                if (function_exists('acf_add_validation_error')) {
                    acf_add_validation_error($input, $message);
                }
            },
            $incoming
        );

        add_action('acf/validate_save_post', array($validator, 'onValidateSavePost'));
    }

    public function onValidateSavePost(): void
    {
        $request = $_POST;
        $payload = is_array($request) ? ($request['acf'] ?? null) : null;
        $this->validate(is_array($request) ? $request : array(), $payload);
    }

    /**
     * @param array<string, mixed> $request
     */
    public function validate(array $request, mixed $acfPayload): void
    {
        try {
            $this->validateUnsafe($request, $acfPayload);
        } catch (Throwable $exception) {
            if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
                error_log('ContentGuard save validation failed safely: ' . $exception->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $request
     */
    private function validateUnsafe(array $request, mixed $acfPayload): void
    {
        $acfPayload  = is_array($acfPayload) ? $acfPayload : null;
        $corePayload = CoreIncomingPayload::fromRequest($request);
        if ($acfPayload === null && $corePayload === null) {
            return;
        }

        if ($this->isIgnoredRequest($request)) {
            return;
        }

        $postId   = $this->resolvePostId($request);
        $postType = $this->resolvePostType($request, $postId);
        if ($postType === '') {
            return;
        }

        if (!$this->statusResolver->isBlockingStatus($this->statusResolver->resolve($request))) {
            return;
        }

        $evaluation = $this->incoming->evaluate($postId, $postType, $corePayload, $acfPayload);
        $nestedMaps = $this->catalog->nestedResolutionMaps(
            $postType,
            $this->catalog->fieldTypesForPostType($postType)
        );
        $fieldPaths   = $nestedMaps['paths'];
        $repeaterKeys = $nestedMaps['repeater_keys'];

        $addError = $this->addError;
        if (!is_callable($addError)) {
            return;
        }

        $generalLines = array();
        $fieldErrors  = array();
        foreach ($evaluation->results as $result) {
            if (!$result->isFailed()) {
                continue;
            }

            $error = $this->errorForResult($result, $fieldPaths, $repeaterKeys);
            if ($error['input'] === '') {
                $generalLines[] = $error['message'];
            } else {
                $fieldErrors[] = $error;
            }
        }

        $general = EditorNoticePresentation::blockingNoticeText($generalLines);
        if ($general !== '') {
            $addError('', $general);
        }

        foreach ($fieldErrors as $error) {
            $addError($error['input'], $error['message']);
        }
    }

    /**
     * @param array<string, mixed> $request
     */
    private function isIgnoredRequest(array $request): bool
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return true;
        }

        $action = isset($request['action']) ? (string) $request['action'] : '';

        return in_array($action, array('heartbeat', 'autosave'), true);
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
    private function resolvePostType(array $request, int $postId): string
    {
        if (isset($request['post_type']) && is_string($request['post_type']) && $request['post_type'] !== '') {
            return $request['post_type'];
        }

        if ($postId > 0 && function_exists('get_post_type')) {
            $type = get_post_type($postId);
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        return '';
    }

    /**
     * @param array<string, list<string>> $fieldPaths
     * @param array<string, string> $repeaterKeys
     * @return array{input: string, message: string}
     */
    private function errorForResult(EvaluationResult $result, array $fieldPaths, array $repeaterKeys = array()): array
    {
        $fieldId = $result->fieldId ?? '';
        $message = $this->issueLine($result);
        if ($fieldId !== '' && isset(CoreFieldCatalog::FIELDS[$fieldId])) {
            return array(
                'input'   => '',
                'message' => $message,
            );
        }

        $input = '';
        $fromContext = trim((string) ($result->context['input_name'] ?? ''));
        if ($fromContext !== '') {
            $input = $fromContext;
        } elseif ($fieldId !== '') {
            $path        = $fieldPaths[$fieldId] ?? array();
            $path        = is_array($path) ? $path : array();
            $repeaterKey = $repeaterKeys[$fieldId] ?? '';
            $input       = $result->code === 'no_rows' && $repeaterKey !== ''
                ? AcfNestedField::repeaterInputName($path, $repeaterKey)
                : AcfNestedField::inputName($fieldId, $path);
        }

        return array(
            'input'   => $input,
            'message' => $message,
        );
    }

    private function issueLine(EvaluationResult $result): string
    {
        if ($result->code === 'no_rows' && $result->message !== '') {
            return $result->message;
        }

        return EditorNoticePresentation::issueLine(
            (string) ($result->context['field_label'] ?? ''),
            $result->message,
            $result->code
        );
    }
}
