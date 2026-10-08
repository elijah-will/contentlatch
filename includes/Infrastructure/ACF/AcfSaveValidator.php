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
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\ACF;

defined('ABSPATH') || exit;

use ContentLatch\Application\Audit\AuditRepeaterCoordinates;
use ContentLatch\Application\DomainMessages;
use ContentLatch\Application\EditorAuditIssues;
use ContentLatch\Application\EditorNoticePresentation;
use ContentLatch\Application\IncomingSaveEvaluator;
use ContentLatch\Application\RuleRepositoryInterface;
use ContentLatch\Domain\EvaluationResult;
use ContentLatch\Infrastructure\WordPress\CoreFieldCatalog;
use ContentLatch\Infrastructure\WordPress\CoreIncomingPayload;
use ContentLatch\Infrastructure\WordPress\IncomingSubmissionSanitizer;
use Throwable;

final class AcfSaveValidator
{
    /**
     * @param callable(string $input, string $message): void $addError
     * @param callable(string $input, array<string, mixed> $contentlatch): void|null $decorateError
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
        $request = $this->submittedAcfRequest();
        $this->validate($request, $request['acf'] ?? null);
    }

    /**
     * ACF ajax validation already verified its nonce before this hook.
     * acf_verify_ajax() repeats that check when ACF is loaded. Routing
     * scalars are sanitized. The acf field tree and Core content are
     * unslashed only so validation still sees the submitted markup.
     *
     * Action stays sanitize_text_field: ACF sends acf/validate_save_post,
     * and sanitize_key would remove the slash.
     *
     * @return array<string, mixed>
     */
    private function submittedAcfRequest(): array
    {
        if (function_exists('acf_verify_ajax') && !acf_verify_ajax()) {
            return array();
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- acf/validate_save_post runs only after ACF verifies its ajax nonce. acf_verify_ajax() rechecks that nonce. ContentLatch does not add a nonce ACF forms do not send.
        $request = array();

        foreach (array('post_ID', 'post_id', '_acf_post_id') as $key) {
            if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
                continue;
            }

            $raw = sanitize_text_field(wp_unslash((string) $_POST[$key]));
            $request[$key] = is_numeric($raw) ? absint($raw) : $raw;
        }

        if (isset($_POST['post_type']) && is_scalar($_POST['post_type'])) {
            $request['post_type'] = sanitize_key(wp_unslash((string) $_POST['post_type']));
        }

        if (isset($_POST['action']) && is_scalar($_POST['action'])) {
            $request['action'] = sanitize_text_field(wp_unslash((string) $_POST['action']));
        }

        foreach (array('post_status', 'original_post_status', 'visibility') as $key) {
            if (isset($_POST[$key]) && is_scalar($_POST[$key])) {
                $request[$key] = sanitize_key(wp_unslash((string) $_POST[$key]));
            }
        }

        foreach (array('publish', 'save', 'saveasdraft', 'private') as $key) {
            if (isset($_POST[$key]) && is_scalar($_POST[$key])) {
                $request[$key] = sanitize_text_field(wp_unslash((string) $_POST[$key]));
            }
        }

        foreach (array(
            'title',
            'post_title',
            'content',
            'post_content',
            'excerpt',
            'post_excerpt',
            'slug',
            'post_name',
            'featured_image',
            '_thumbnail_id',
            'thumbnail_id',
            'featured_media',
            'author',
            'post_author',
        ) as $key) {
            if (array_key_exists($key, $_POST)) {
                // acf_verify_ajax / ACF hook nonce already passed. IncomingSubmissionSanitizer
                // applies field-appropriate WP sanitizers; sniff cannot see that callback.
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $request[$key] = IncomingSubmissionSanitizer::coreField($key, wp_unslash($_POST[$key]));
            }
        }

        if (isset($_POST['acf']) && is_array($_POST['acf'])) {
            $postType = isset($request['post_type']) ? (string) $request['post_type'] : '';
            $types    = $postType !== ''
                ? IncomingSubmissionSanitizer::acfFieldTypes($this->catalog->fieldsForPostType($postType))
                : array();
            // Evaluation-only tree. Nested indexes preserved; leaves sanitized by ACF type when known.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $request['acf'] = IncomingSubmissionSanitizer::acfTree(wp_unslash($_POST['acf']), $types);
        }

        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return $request;
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
                error_log('ContentLatch save validation failed safely: ' . $exception->getMessage());
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
                $error['contentlatch'] = $this->contentGuardMetadata($result);
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

            $this->attachContentLatchMetadata(
                $error['input'],
                is_array($error['contentlatch'] ?? null) ? $error['contentlatch'] : array()
            );
        }
    }

    /**
     * Classic post form includes post_status. Gutenberg ACF AJAX does not.
     * contentlatch_field is navigation-only and is not consulted here.
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
     * @param array<string, mixed> $contentlatch
     */
    private function attachContentLatchMetadata(string $input, array $contentlatch): void
    {
        if ($contentlatch === array()) {
            return;
        }

        $decorate = $this->decorateError;
        if (is_callable($decorate)) {
            $decorate($input, $contentlatch);

            return;
        }

        self::decorateAcfValidationError($input, $contentlatch);
    }

    /**
     * Narrowest ACF hook: extra keys on the error object we just added.
     * ACF 6.8.9 only stores input/message; unknown keys survive JSON.
     *
     * @param array<string, mixed> $contentlatch
     */
    private static function decorateAcfValidationError(string $input, array $contentlatch): void
    {
        if ($contentlatch === array() || !function_exists('acf')) {
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
            if (array_key_exists('contentlatch', $error)) {
                continue;
            }

            $acf->validation->errors[$i]['contentlatch'] = $contentlatch;

            return;
        }
    }

    private function issueLine(EvaluationResult $result): string
    {
        if ($result->code === 'no_rows' && $result->message !== '') {
            return DomainMessages::present($result->message);
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
            return sprintf(
                /* translators: 1: validation message. 2: 1-based row number. */
                __('%1$s in row %2$d.', 'contentlatch'),
                rtrim($line, '.'),
                $displayRow
            );
        }

        return $line;
    }
}
