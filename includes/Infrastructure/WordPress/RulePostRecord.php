<?php
/**
 * Stored CPT row for a rule.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

final class RulePostRecord
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $wpStatus,
        public readonly string $json,
        public readonly string $targetPostType,
    ) {
    }
}
