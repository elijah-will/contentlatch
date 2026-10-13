<?php
/**
 * Current metadata used to enrich persisted audit findings.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Application\Audit;

defined('ABSPATH') || exit;

use ContentLatch\Domain\Rule;

interface AuditExportMetadata
{
    /**
     * Null means the post no longer exists; an empty title means untitled.
     * Implementations must not retain an ever-growing cache across calls.
     *
     * @return array{title: string, status: string, edit_url: string}|null
     */
    public function post(int $postId): ?array;

    public function rule(int|string $ruleId): ?Rule;
}
