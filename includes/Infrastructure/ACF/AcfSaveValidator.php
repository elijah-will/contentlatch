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

defined('ABSPATH') || exit;

use ContentGuard\Application\Audit\AuditRepeaterCoordinates;
use ContentGuard\Application\EditorAuditIssues;
use ContentGuard\Application\EditorNoticePresentation;
use ContentGuard\Application\IncomingSaveEvaluator;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\EvaluationResult;
use ContentGuard\Infrastructure\WordPress\CoreFieldCatalog;
use ContentGuard\Infrastructure\WordPress\CoreIncomingPayload;
use ContentGuard\Infrastructure\WordPress\HttpRequest;
use Throwable;

final class AcfSaveValidator
{
    /**
     * @param callable(string $input, string $message): void $addError
     * @param callable(string $input, array<string, mixed> $contentguard): void|null $decorateError
     */
    public function __construct(
        private RuleRepositoryInterface $repository,
        private AcfFieldCatalog $catalog,
        private IntendedPostStatusResolver $statusResolver,
        private mixed $addError,
        private IncomingSaveEvaluator $incoming,
        private mixed $decorateError = null,
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
        $request = HttpRequest::unslash(is_array($_POST) ? $_POST : array());
        $payload = $request['acf'] ?? null;
        $this->validate($request, $payload);
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

        $coreLines   = array();
        $fieldErrors = array();
        foreach ($evaluation->results as $result) {
            if (!$result->isFailed()) {
                continue;
            }

            $error = $this->errorForResult($result, $fieldPaths, $repeaterKeys);
            if ($error['input'] === '') {
                $coreLines[] = $error['message'];
            } else {
                $error['contentguard'] = $this->contentGuardMetadata($result);
                $fieldErrors[]         = $error;
            }
        }

        $isClassic = $this->isClassicEditorRequest($request);
        if ($isClassic) {
            $summary = EditorAuditIssues::classicValidationNotice(
                EditorAuditIssues::fromEvaluation($evaluation, $postId)
            );
            if ($summary !== '') {
                $addError('', $summary);
            }
        } else {
            $general = EditorNoticePresentation::blockingNoticeText($coreLines);
            if ($general !== '') {
                $addError('', $general);
            }
        }

        foreach ($fieldErrors as $error) {
            $addError($error['input'], $error['message']);
            if ($isClassic) {
                continue;
            }

            $this->attachContentGuardMetadata(
                $error['input'],
                is_array($error['contentguard'] ?? null) ? $error['contentguard'] : array()
            );
        }
    }

    /**
     * Classic post form includes post_status. Gutenberg ACF AJAX does not.
     * contentguard_field is navigation-only and is not consulted here.
     *
     * @param array<string, mixed> $request
     */
    private function isClassicEditorRequest(array $request): bool
    {
        return isset($request['post_status'])
            && is_scalar($request['post_status'])
            && trim((string) $request['post_status']) !== '';
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

    /**
     * Gutenberg ACF AJAX extras only. Classic keeps the HTML summary and
     * never receives this object. Native ACF errors are not decorated.
     *
     * @return array<string, mixed>
     */
    private function contentGuardMetadata(EvaluationResult $result): array
    {
        $issue = EditorAuditIssues::fromResult($result);
        $field = (string) ($issue['fieldKey'] ?? '');
        if ($field === '') {
            return array();
        }

        $payload = array(
            'field'    => $field,
            'fieldKey' => $field,
            'label'    => (string) ($issue['label'] ?? ''),
            'message'  => (string) ($issue['message'] ?? ''),
        );

        if (isset($issue['repeaterPath']) && is_array($issue['repeaterPath']) && $issue['repeaterPath'] !== array()) {
            $payload['repeaterPath'] = $issue['repeaterPath'];
        }
        if (!empty($issue['repeaterPathInvalid'])) {
            $payload['repeaterPathInvalid'] = true;
        }

        $layout = (string) ($issue['layout'] ?? '');
        if ($layout !== '') {
            $payload['layout'] = $layout;
        }
        if (isset($issue['affectedRows']) && is_array($issue['affectedRows']) && $issue['affectedRows'] !== array()) {
            $payload['affectedRows'] = $issue['affectedRows'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $contentguard
     */
    private function attachContentGuardMetadata(string $input, array $contentguard): void
    {
        if ($contentguard === array()) {
            return;
        }

        $decorate = $this->decorateError;
        if (is_callable($decorate)) {
            $decorate($input, $contentguard);

            return;
        }

        self::decorateAcfValidationError($input, $contentguard);
    }

    /**
     * Narrowest ACF hook: extra keys on the error object we just added.
     * ACF 6.8.9 only stores input/message; unknown keys survive JSON.
     *
     * @param array<string, mixed> $contentguard
     */
    private static function decorateAcfValidationError(string $input, array $contentguard): void
    {
        if ($contentguard === array() || !function_exists('acf')) {
            return;
        }

        $acf = acf();
        if (!is_object($acf) || !isset($acf->validation) || !is_object($acf->validation)) {
            return;
        }
        if (!isset($acf->validation->errors) || !is_array($acf->validation->errors)) {
            return;
        }

        for ($i = count($acf->validation->errors) - 1; $i >= 0; $i--) {
            $error = $acf->validation->errors[$i];
            if (!is_array($error) || (string) ($error['input'] ?? '') !== $input) {
                continue;
            }
            if (array_key_exists('contentguard', $error)) {
                continue;
            }

            $acf->validation->errors[$i]['contentguard'] = $contentguard;

            return;
        }
    }

    private function issueLine(EvaluationResult $result): string
    {
        if ($result->code === 'no_rows' && $result->message !== '') {
            return $result->message;
        }

        $line = EditorNoticePresentation::issueLine(
            (string) ($result->context['field_label'] ?? ''),
            $result->message,
            $result->code
        );

        if (AuditRepeaterCoordinates::instanceChain($result->context) !== array()) {
            return $line;
        }

        $displayRow = 0;
        $row        = $result->context['display_row'] ?? null;
        if (is_int($row) || (is_numeric($row) && (int) $row > 0)) {
            $displayRow = (int) $row;
        }

        $layout = trim((string) ($result->context['layout'] ?? ''));
        if ($displayRow > 0 && $layout === '') {
            return rtrim($line, '.') . sprintf(' in row %d.', $displayRow);
        }

        return $line;
    }
}
