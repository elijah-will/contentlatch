<?php
/**
 * Save-time incoming evaluation through the composite provider architecture.
 *
 * Callers supply submitted Core and ACF payloads. This class does not read
 * request superglobals, does not know WordPress hooks, and does not branch
 * inside RuleEngine. A payload source that was not submitted is omitted so
 * missing Core values in an ACF-only request cannot false-fail Core rules.
 * REST updates are PATCH-like: omitted Core fields keep their stored values,
 * so an existing featured image is not treated as empty when Gutenberg leaves
 * featured_media out of the request. Explicit empty submissions still win.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

defined('ABSPATH') || exit;

use ContentGuard\Application\Integration\CompositeValueProvider;
use ContentGuard\Application\Integration\FieldCatalog;
use ContentGuard\Domain\ContentEvaluation;
use ContentGuard\Domain\Rule;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfIntegration;
use ContentGuard\Infrastructure\WordPress\CoreIntegration;

final class IncomingSaveEvaluator
{
    public function __construct(
        private RuleEngine $engine,
        private RuleRepositoryInterface $repository,
        private FieldCatalog $catalog,
        private CoreIntegration $core,
        private AcfIntegration $acf,
    ) {
    }

    /**
     * @param array<string, mixed>|null $corePayload Submitted Core values, or null when Core was not in this request.
     * @param array<string, mixed>|null $acfPayload  Submitted ACF map, or null when ACF was not in this request.
     */
    public function evaluate(
        int $postId,
        string $postType,
        mixed $corePayload,
        mixed $acfPayload,
    ): ContentEvaluation {
        $hasCore = is_array($corePayload) && $corePayload !== array();
        $hasAcf  = is_array($acfPayload);
        $coreTypes = $this->core->fieldTypesForPostType($postType);
        $acfTypes  = $this->acf->fieldTypesForPostType($postType);

        $rules = array();
        foreach ($this->repository->findActiveForPostType($postType) as $rule) {
            if ($this->ruleIsCovered($rule, $coreTypes, $acfTypes, $hasCore, $hasAcf)) {
                $rules[] = $rule;
            }
        }

        $fieldTypes = array_intersect_key(
            $this->catalog->fieldTypesForPostType($postType),
            $this->referencedFieldKeys($rules)
        );

        $providers = array();
        if ($hasCore) {
            $providers[] = $this->core->incomingProvider($corePayload, $postType, $fieldTypes);
            if ($postId > 0) {
                $providers[] = $this->core->storedProvider($postId, $postType, $fieldTypes);
            }
        }
        if ($hasAcf) {
            $providers[] = $this->acf->incomingProvider($acfPayload, $postType, $fieldTypes);
        }

        return $this->engine
            ->evaluate($rules, new CompositeValueProvider($providers), $postId)
            ->withPostType($postType);
    }

    /**
     * @param array<string, string> $coreTypes
     * @param array<string, string> $acfTypes
     */
    private function ruleIsCovered(
        Rule $rule,
        array $coreTypes,
        array $acfTypes,
        bool $hasCore,
        bool $hasAcf,
    ): bool {
        foreach ($this->ruleFieldIds($rule) as $id => $_true) {
            if (isset($coreTypes[$id]) && !$hasCore) {
                return false;
            }
            if (isset($acfTypes[$id]) && !$hasAcf) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Rule[] $rules
     * @return array<string, true>
     */
    private function referencedFieldKeys(array $rules): array
    {
        $keys = array();
        foreach ($rules as $rule) {
            foreach ($this->ruleFieldIds($rule) as $id => $_true) {
                $keys[$id] = true;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, true>
     */
    private function ruleFieldIds(Rule $rule): array
    {
        $keys = array();
        foreach ($rule->conditions as $condition) {
            $keys[$condition->field->resolutionId()] = true;
        }
        foreach ($rule->validations as $validation) {
            $keys[$validation->field->resolutionId()] = true;
        }

        return $keys;
    }
}
