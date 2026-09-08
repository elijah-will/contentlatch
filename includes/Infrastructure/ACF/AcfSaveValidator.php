<?php
/**
 * ACF save-time validation adapter.
 *
 * Hooked to acf/validate_save_post. Reads $_POST only at this boundary,
 * evaluates through ContentEvaluator + RuleEngine, and reports blocking
 * failures with acf_add_validation_error(). Warning results are ignored
 * here because ACF treats every validation error as a hard save failure.
 * Post-save warning notices are handled by SaveWarningNotifier.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\ACF;

use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Application\RuleRepositoryInterface;
use ContentGuard\Domain\EvaluationResult;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleEngine;
use Throwable;

final class AcfSaveValidator
{
    /**
     * @param callable(string $input, string $message): void $addError
     */
    public function __construct(
        private ContentEvaluator $evaluator,
        private RuleRepositoryInterface $repository,
        private AcfFieldCatalog $catalog,
        private IntendedPostStatusResolver $statusResolver,
        private mixed $addError,
    ) {
    }

    public static function register(RuleRepositoryInterface $repository): void
    {
        $validator = new self(
            new ContentEvaluator($repository, RuleEngine::v1()),
            $repository,
            new AcfFieldCatalog(),
            new IntendedPostStatusResolver(),
            static function (string $input, string $message): void {
                if (function_exists('acf_add_validation_error')) {
                    acf_add_validation_error($input, $message);
                }
            }
        );

        add_action('acf/validate_save_post', array($validator, 'onValidateSavePost'));
    }

    public function onValidateSavePost(): void
    {
        $request = $_POST;
        $payload = $request['acf'] ?? null;
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
        if (!is_array($acfPayload)) {
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

        $catalogTypes = $this->catalog->fieldTypesForPostType($postType);
        $rules        = $this->repository->findActiveForPostType($postType);
        $fieldTypes   = array_intersect_key($catalogTypes, $this->referencedFieldKeys($rules));
        $nestedMaps   = $this->catalog->nestedResolutionMaps($postType, $fieldTypes);
        $fieldPaths   = $nestedMaps['paths'];

        $evaluation = $this->evaluator->evaluate(
            $postId,
            $postType,
            new AcfIncomingValueProvider(
                $acfPayload,
                new AcfValueNormalizer(),
                $fieldTypes,
                $fieldPaths,
                $nestedMaps['names'],
                $nestedMaps['repeater_keys'],
                $nestedMaps['flex_keys'] ?? array(),
                $nestedMaps['layouts'] ?? array(),
                $nestedMaps['clone_keys'] ?? array()
            )
        );

        foreach ($evaluation->results as $result) {
            if (!$result->isFailed()) {
                continue;
            }

            $this->reportError($result, $fieldPaths, $nestedMaps['repeater_keys']);
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

    /**
     * @param array<string, list<string>> $fieldPaths
     * @param array<string, string> $repeaterKeys
     */
    private function reportError(EvaluationResult $result, array $fieldPaths, array $repeaterKeys = array()): void
    {
        $addError = $this->addError;
        if (!is_callable($addError)) {
            return;
        }

        $input = '';
        $fromContext = trim((string) ($result->context['input_name'] ?? ''));
        if ($fromContext !== '') {
            $input = $fromContext;
        } elseif ($result->fieldId !== null && $result->fieldId !== '') {
            $path        = $fieldPaths[$result->fieldId] ?? array();
            $path        = is_array($path) ? $path : array();
            $repeaterKey = $repeaterKeys[$result->fieldId] ?? '';
            $input       = $result->code === 'no_rows' && $repeaterKey !== ''
                ? AcfNestedField::repeaterInputName($path, $repeaterKey)
                : AcfNestedField::inputName($result->fieldId, $path);
        }

        $addError($input, $this->errorMessage($result));
    }

    private function errorMessage(EvaluationResult $result): string
    {
        $message = $result->message;
        $label   = (string) ($result->context['field_label'] ?? '');

        if ($result->code === 'no_rows' && $message !== '') {
            return $message;
        }

        if ($result->code === 'required' && $message === 'This field is required.' && $label !== '') {
            return sprintf('%s is required.', $label);
        }

        if ($message !== '') {
            return $message;
        }

        return $label !== '' ? sprintf('%s is invalid.', $label) : 'Content validation failed.';
    }
}
