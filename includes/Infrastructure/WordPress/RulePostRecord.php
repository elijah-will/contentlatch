<?php
/**
 * Stored CPT row for a rule.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Infrastructure\WordPress;

defined('ABSPATH') || exit;

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
