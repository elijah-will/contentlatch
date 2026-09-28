<?php
/**
 * Scan hit: post ID and type only.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Audit;

defined('ABSPATH') || exit;

final class AuditPost
{
    public function __construct(
        public readonly int $id,
        public readonly string $postType,
    ) {
    }
}
