<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\AuditAjaxController;
use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Tests\Support\InMemoryAuditLock;
use ContentGuard\Tests\Support\InMemoryAuditPostScanner;
use ContentGuard\Tests\Support\InMemoryAuditStore;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AuditAjaxControllerTest extends TestCase
{
    public function testUnauthorizedAndInvalidNonceAreRejected(): void
    {
        $controller = $this->controller(false, true);
        $denied = $controller->dispatch(AuditAjaxController::ACTION_START, array('_wpnonce' => 'ok'));
        $this->assertFalse($denied['ok']);
        $this->assertSame('You are not allowed to run ContentGuard audits.', $denied['message']);

        $controller = $this->controller(true, false);
        $badNonce = $controller->dispatch(AuditAjaxController::ACTION_START, array('_wpnonce' => 'nope'));
        $this->assertFalse($badNonce['ok']);
        $this->assertSame('Invalid audit nonce.', $badNonce['message']);
    }

    public function testUnknownActionAndInvalidRunAreRejected(): void
    {
        $controller = $this->controller(true, true);
        $unknown = $controller->dispatch('nope', array('_wpnonce' => 'ok'));
        $this->assertFalse($unknown['ok']);

        $invalid = $controller->dispatch(
            AuditAjaxController::ACTION_BATCH,
            array('_wpnonce' => 'ok', 'run_id' => '0')
        );
        $this->assertFalse($invalid['ok']);
        $this->assertSame('Invalid audit run.', $invalid['message']);
    }

    public function testAuthorizedStartReturnsRun(): void
    {
        $controller = $this->controller(true, true);
        $started = $controller->dispatch(
            AuditAjaxController::ACTION_START,
            array('_wpnonce' => 'ok', 'user_id' => '4')
        );

        $this->assertTrue($started['ok']);
        $this->assertSame('pending', $started['run']['status']);
    }

    /**
     * @param callable(): bool $can
     */
    private function controller(bool $canManage, bool $validNonce): AuditAjaxController
    {
        $rules = array(
            RuleFactory::rule(array('id' => 1, 'postType' => 'recipe')),
        );
        $repository = new InMemoryRuleRepository($rules);
        $service = new ContentAuditService(
            new InMemoryAuditStore(),
            new InMemoryAuditPostScanner(
                array(
                    array('id' => 10, 'postType' => 'recipe', 'status' => 'publish'),
                )
            ),
            new InMemoryAuditLock(),
            $repository,
            new ContentEvaluator($repository, RuleEngine::v1()),
            new AcfFieldCatalog(
                static fn (): array => array(
                    array(
                        'key'   => 'field_type',
                        'name'  => 'product_type',
                        'label' => 'Product Type',
                        'type'  => 'select',
                    ),
                    array(
                        'key'   => 'field_ingredients',
                        'name'  => 'ingredients',
                        'label' => 'Ingredients',
                        'type'  => 'textarea',
                    ),
                )
            ),
            static fn (): ArrayValueProvider => new ArrayValueProvider(array()),
            static function (): void {
            },
            static fn (): int => 1_000_000,
            1
        );

        return new AuditAjaxController(
            $service,
            static fn (): bool => $canManage,
            static fn (string $nonce): bool => $validNonce && $nonce === 'ok'
        );
    }
}
