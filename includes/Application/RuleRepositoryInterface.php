<?php
/**
 * Rule persistence port.
 *
 * Inactive rules are filtered here, not in the domain engine.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

use ContentGuard\Domain\Rule;

interface RuleRepositoryInterface
{
    /**
     * Active rules that target the given post type.
     *
     * @return Rule[]
     */
    public function findActiveForPostType(string $postType): array;

    /**
     * Distinct post types that have at least one active rule.
     *
     * @return string[]
     */
    public function findActivePostTypes(): array;

    public function find(int|string $id): ?Rule;

    /**
     * All persisted rules, including inactive. Used by the admin list.
     *
     * @return Rule[]
     */
    public function findAll(): array;

    /**
     * Create or update a rule. New rules receive a persistence ID.
     */
    public function save(Rule $rule): Rule;

    public function delete(int|string $id): bool;
}
