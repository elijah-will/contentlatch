<?php
/**
 * Capability- and nonce-gated rule mutations.
 *
 * Evaluation reads go through RuleRepositoryInterface directly so editors
 * can be validated without manage_contentguard.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Application;

defined('ABSPATH') || exit;

use ContentGuard\Application\Exception\ForbiddenRuleMutationException;
use ContentGuard\Domain\Rule;

final class RuleCommandService
{
    public const NONCE_ACTION = 'contentguard_manage_rule';

    /**
     * @param callable(): bool           $canManage
     * @param callable(string): bool     $verifyNonce
     */
    public function __construct(
        private RuleRepositoryInterface $repository,
        private mixed $canManage,
        private mixed $verifyNonce,
    ) {
    }

    public function save(Rule $rule, string $nonce): Rule
    {
        $this->assertCanMutate($nonce);

        return $this->repository->save($rule);
    }

    public function delete(int|string $id, string $nonce): bool
    {
        $this->assertCanMutate($nonce);

        return $this->repository->delete($id);
    }

    private function assertCanMutate(string $nonce): void
    {
        $canManage = $this->canManage;
        if (!is_callable($canManage) || !$canManage()) {
            throw new ForbiddenRuleMutationException(
                I18n::translate('You are not allowed to manage ContentGuard rules.')
            );
        }

        $verifyNonce = $this->verifyNonce;
        if (!is_callable($verifyNonce) || !$verifyNonce($nonce)) {
            throw new ForbiddenRuleMutationException(
                I18n::translate('Invalid rule management nonce.')
            );
        }
    }
}
