<?php
/**
 * Application entry point for save-time validation and audit.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application;

defined('ABSPATH') || exit;

use ContentLatch\Domain\ContentEvaluation;
use ContentLatch\Domain\Contracts\FieldValueProviderInterface;
use ContentLatch\Domain\RuleEngine;

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
