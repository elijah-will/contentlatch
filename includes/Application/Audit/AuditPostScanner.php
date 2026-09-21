<?php
/**
 * ID-only post discovery for audit batches.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application\Audit;

defined('ABSPATH') || exit;

interface AuditPostScanner
{
    /**
     * @param string[] $postTypes
     * @param string[] $statuses
     * @return AuditPost[]
     */
    public function scan(array $postTypes, array $statuses, int $cursor, int $limit): array;

    /**
     * @param string[] $postTypes
     * @param string[] $statuses
     */
    public function count(array $postTypes, array $statuses): int;
}
