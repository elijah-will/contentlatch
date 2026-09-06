<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\Exception\ForbiddenRuleMutationException;
use ContentGuard\Application\RuleCommandService;
use ContentGuard\Application\RuleDocumentValidator;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleCommandServiceTest extends TestCase
{
    public function testSaveRequiresCapabilityAndNonce(): void
    {
        $repository = new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $service    = new RuleCommandService(
            $repository,
            static fn (): bool => false,
            static fn (): bool => true
        );

        $this->expectException(ForbiddenRuleMutationException::class);
        $service->save(RuleFactory::rule(array('id' => '')), 'valid-nonce');
    }

    public function testSaveRequiresValidNonce(): void
    {
        $repository = new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $service    = new RuleCommandService(
            $repository,
            static fn (): bool => true,
            static fn (): bool => false
        );

        $this->expectException(ForbiddenRuleMutationException::class);
        $service->save(RuleFactory::rule(array('id' => '')), 'bad-nonce');
    }

    public function testAuthorizedSaveAndDelete(): void
    {
        $repository = new InMemoryRuleRepository(array(), RuleDocumentValidator::v1());
        $service    = new RuleCommandService(
            $repository,
            static fn (): bool => true,
            static fn (string $nonce): bool => $nonce === 'ok'
        );

        $saved = $service->save(RuleFactory::rule(array('id' => '', 'name' => 'Managed')), 'ok');
        $this->assertSame(1, $saved->id);
        $this->assertSame('Managed', $repository->find(1)?->name);

        $this->assertTrue($service->delete(1, 'ok'));
        $this->assertNull($repository->find(1));
    }

    public function testEvaluationPathDoesNotRequireCapability(): void
    {
        $repository = new InMemoryRuleRepository(
            array(
                RuleFactory::rule(array('status' => RuleStatus::Active)),
            )
        );

        $this->assertCount(1, $repository->findActiveForPostType('product'));
    }
}
