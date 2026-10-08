<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

require_once dirname(__DIR__, 2) . '/Support/wordpress-admin-functions.php';

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
        $controller = $this->controller(true, true);
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

    public function testWordPressAjaxEntryRejectsMissingInvalidNonceAndMissingCapability(): void
    {
        unset($GLOBALS['contentlatch_test_json']);
        $controller = $this->controller(true, true);
        $deniedCap  = $this->controller(false, true);
        $previous   = $_POST;

        try {
            $_POST = array('_wpnonce' => '', 'user_id' => '4');
            $controller->start();
            $this->assertFalse(($GLOBALS['contentlatch_test_json']['response']['ok'] ?? true));
            $this->assertSame('Invalid audit nonce.', $GLOBALS['contentlatch_test_json']['response']['message']);
            $idle = $controller->dispatch(AuditAjaxController::ACTION_STATUS, array('_wpnonce' => 'ok'));
            $this->assertNull($idle['run']);

            unset($GLOBALS['contentlatch_test_json']);
            $_POST['_wpnonce'] = 'forged';
            $controller->start();
            $this->assertFalse(($GLOBALS['contentlatch_test_json']['response']['ok'] ?? true));
            $this->assertNull(
                $controller->dispatch(AuditAjaxController::ACTION_STATUS, array('_wpnonce' => 'ok'))['run']
            );

            unset($GLOBALS['contentlatch_test_json']);
            $_POST['_wpnonce'] = 'ok';
            $deniedCap->start();
            $this->assertFalse(($GLOBALS['contentlatch_test_json']['response']['ok'] ?? true));
            $this->assertSame(
                'You are not allowed to run ContentLatch audits.',
                $GLOBALS['contentlatch_test_json']['response']['message']
            );
            $this->assertNull(
                $controller->dispatch(AuditAjaxController::ACTION_STATUS, array('_wpnonce' => 'ok'))['run']
            );

            unset($GLOBALS['contentlatch_test_json']);
            $controller->start();
            $this->assertTrue(($GLOBALS['contentlatch_test_json']['response']['ok'] ?? false));
            $runId = (int) ($GLOBALS['contentlatch_test_json']['response']['run']['id'] ?? 0);
            $this->assertGreaterThan(0, $runId);

            unset($GLOBALS['contentlatch_test_json']);
            $_POST = array('_wpnonce' => 'forged', 'run_id' => (string) $runId);
            $controller->batch();
            $this->assertFalse(($GLOBALS['contentlatch_test_json']['response']['ok'] ?? true));
            $this->assertSame(
                'pending',
                $controller->dispatch(
                    AuditAjaxController::ACTION_STATUS,
                    array('_wpnonce' => 'ok', 'run_id' => (string) $runId)
                )['run']['status']
            );

            unset($GLOBALS['contentlatch_test_json']);
            $_POST['_wpnonce'] = '';
            $controller->cancel();
            $this->assertFalse(($GLOBALS['contentlatch_test_json']['response']['ok'] ?? true));
            $this->assertSame(
                'pending',
                $controller->dispatch(
                    AuditAjaxController::ACTION_STATUS,
                    array('_wpnonce' => 'ok', 'run_id' => (string) $runId)
                )['run']['status']
            );

            unset($GLOBALS['contentlatch_test_json']);
            $_POST['_wpnonce'] = 'ok';
            $deniedCap->cancel();
            $this->assertSame(
                'pending',
                $controller->dispatch(
                    AuditAjaxController::ACTION_STATUS,
                    array('_wpnonce' => 'ok', 'run_id' => (string) $runId)
                )['run']['status']
            );
        } finally {
            $_POST = $previous;
            unset($GLOBALS['contentlatch_test_json']);
        }
    }

    public function testWordPressAjaxEntryUsesExplicitCheckAjaxReferer(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/AuditAjaxController.php');
        $this->assertStringContainsString(
            "check_ajax_referer(ContentAuditService::NONCE_ACTION, '_wpnonce', false) === false",
            $src
        );
        $this->assertStringNotContainsString("function_exists('check_ajax_referer')", $src);
        $method = substr($src, (int) strpos($src, 'function authorizeWordPressAjax'));
        $method = substr($method, 0, (int) strpos($method, 'function authorizeDispatched'));
        $this->assertStringContainsString('check_ajax_referer', $method);
        $this->assertStringNotContainsString('$this->verifyNonce', $method);

        $postedInt = substr($src, (int) strpos($src, 'function postedInt'));
        $this->assertStringNotContainsString('check_ajax_referer', $postedInt);
        $this->assertStringNotContainsString('$_POST', $postedInt);

        $postedNonce = substr($src, (int) strpos($src, 'function postedNonce'));
        $postedNonce = substr($postedNonce, 0, (int) strpos($postedNonce, 'function postedInt'));
        $this->assertStringNotContainsString('$_POST', $postedNonce);
        $this->assertStringContainsString('$this->dispatched', $method);
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
