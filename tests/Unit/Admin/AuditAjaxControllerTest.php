<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

use ContentLatch\Admin\AuditAjaxController;
use ContentLatch\Application\Audit\ContentAuditService;
use ContentLatch\Application\ContentEvaluator;
use ContentLatch\Domain\ArrayValueProvider;
use ContentLatch\Domain\RuleEngine;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;
use ContentLatch\Infrastructure\ACF\AcfIntegration;
use ContentLatch\Tests\Support\InMemoryRuleRepository;
use ContentLatch\Tests\Support\InMemoryAuditLock;
use ContentLatch\Tests\Support\InMemoryAuditPostScanner;
use ContentLatch\Tests\Support\InMemoryAuditStore;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AuditAjaxControllerTest extends TestCase
{
    public function testUnauthorizedAndInvalidNonceAreRejected(): void
    {
        $controller = $this->controller(false, true);
        $denied = $controller->dispatch(AuditAjaxController::ACTION_START, array('_wpnonce' => 'ok'));
        $this->assertFalse($denied['ok']);
        $this->assertSame('You are not allowed to run ContentLatch audits.', $denied['message']);

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

    public function testHttpStartBatchAndCancelSanitizeNonceAndIds(): void
    {
        $seen = null;
        $controller = $this->controller(
            true,
            true,
            static function (string $nonce) use (&$seen): bool {
                $seen = $nonce;

                return $nonce === 'ok';
            }
        );
        $previous = $_POST;

        try {
            $_POST = array(
                '_wpnonce' => "ok<script>alert(1)</script>",
                'user_id'  => '4',
                'run_id'   => 'nope',
            );
            $denied = $this->controller(false, true);
            $denied->start();
            $idle = $controller->dispatch(AuditAjaxController::ACTION_STATUS, array('_wpnonce' => 'ok'));
            $this->assertNull($idle['run']);

            $controller->start();
            $this->assertSame('ok', $seen);
            $started = $controller->dispatch(AuditAjaxController::ACTION_STATUS, array('_wpnonce' => 'ok'));
            $this->assertNotNull($started['run']);
            $runId = (int) $started['run']['id'];

            $_POST = array(
                '_wpnonce' => 'ok',
                'run_id'   => $runId . 'abc',
            );
            $controller->batch();
            $controller->cancel();
            $cancelled = $controller->dispatch(
                AuditAjaxController::ACTION_STATUS,
                array('_wpnonce' => 'ok', 'run_id' => (string) $runId)
            );
            $this->assertSame('cancelled', $cancelled['run']['status']);

            $unknown = $controller->dispatch('not-an-action', array('_wpnonce' => 'ok'));
            $this->assertFalse($unknown['ok']);
            $this->assertSame('Unknown audit action.', $unknown['message']);

            $blocked = $this->controller(false, true)->dispatch('not-an-action', array('_wpnonce' => 'ok'));
            $this->assertSame('You are not allowed to run ContentLatch audits.', $blocked['message']);
        } finally {
            $_POST = $previous;
        }
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
     * @param callable(string): bool|null $verifyNonce
     */
    private function controller(bool $canManage, bool $validNonce, ?callable $verifyNonce = null): AuditAjaxController
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
            new AcfIntegration(new AcfFieldCatalog(
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
            )),
            static fn (): ArrayValueProvider => new ArrayValueProvider(array()),
            static function (): void {
            },
            static fn (): int => 1_000_000,
            1
        );

        return new AuditAjaxController(
            $service,
            static fn (): bool => $canManage,
            $verifyNonce ?? static fn (string $nonce): bool => $validNonce && $nonce === 'ok'
        );
    }
}
