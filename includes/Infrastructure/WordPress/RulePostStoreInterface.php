<?php
/**
 * Storage port so the CPT repository can be tested without WordPress.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Infrastructure\WordPress;

interface RulePostStoreInterface
{
    public function insert(string $title, string $wpStatus, string $json, string $targetPostType): int;

    public function update(int $id, string $title, string $wpStatus, string $json, string $targetPostType): void;

    public function delete(int $id): bool;

    public function get(int $id): ?RulePostRecord;

    /**
     * @return RulePostRecord[]
     */
    public function findByTargetPostType(string $targetPostType, bool $activeOnly): array;

    /**
     * @return string[]
     */
    public function findActiveTargetPostTypes(): array;

    /**
     * @return RulePostRecord[]
     */
    public function findAll(): array;
}
