<?php
/**
 * Application entry point for save-time validation and audit.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

use ContentGuard\Domain\ContentEvaluation;
use ContentGuard\Domain\Contracts\FieldValueProviderInterface;
use ContentGuard\Domain\RuleEngine;

final class ContentEvaluator
{
    public function __construct(
        private RuleRepositoryInterface $rules,
        private RuleEngine $engine,
    ) {
    }

    public function evaluate(
        int $postId,
        string $postType,
        FieldValueProviderInterface $provider,
    ): ContentEvaluation {
        $rules = $this->rules->findActiveForPostType($postType);

        return $this->engine
            ->evaluate($rules, $provider, $postId)
            ->withPostType($postType);
    }
}
