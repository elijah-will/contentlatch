<?php
/**
 * Scan hit: post ID and type only.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Audit;

defined('ABSPATH') || exit;

final class AuditPost
{
    public function __construct(
        public readonly int $id,
        public readonly string $postType,
    ) {
    }
}
