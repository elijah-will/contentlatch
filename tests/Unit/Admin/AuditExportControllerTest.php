<?php
/**
 * Secure admin-post download of the latest completed audit CSV.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

require_once dirname(__DIR__, 2) . '/Support/wordpress-admin-functions.php';
require_once dirname(__DIR__, 2) . '/Support/audit-export-admin-functions.php';

use ContentLatch\Admin\AuditExportController;
use ContentLatch\Application\Audit\AuditCsvExporter;
use ContentLatch\Application\Audit\AuditExportMetadata;
use ContentLatch\Application\Audit\AuditFinding;
use ContentLatch\Application\Audit\AuditFindingQuery;
use ContentLatch\Application\Audit\AuditRun;
use ContentLatch\Application\Audit\AuditRunStatus;
use ContentLatch\Application\Audit\AuditStoreInterface;
use ContentLatch\Application\Audit\ContentAuditService;
use ContentLatch\Application\ContentEvaluator;
use ContentLatch\Application\Exception\AuditException;
use ContentLatch\Domain\ArrayValueProvider;
use ContentLatch\Domain\Rule;
use ContentLatch\Domain\RuleEngine;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Infrastructure\ACF\AcfFieldCatalog;
use ContentLatch\Infrastructure\ACF\AcfIntegration;
use ContentLatch\Tests\Support\AdminResponseEnded;
use ContentLatch\Tests\Support\InMemoryAuditLock;
use ContentLatch\Tests\Support\InMemoryAuditPostScanner;
use ContentLatch\Tests\Support\InMemoryAuditStore;
use ContentLatch\Tests\Support\InMemoryRuleRepository;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AuditExportControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $request = array();

    /** @var array<string, mixed> */
    private array $get = array();

    /** @var array<string, mixed> */
    private array $post = array();

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = $_REQUEST;
        $this->get     = $_GET;
        $this->post    = $_POST;
        $_REQUEST      = array();
        $_GET          = array();
        $_POST         = array();
        unset(
            $GLOBALS['contentlatch_test_wp_die'],
            $GLOBALS['contentlatch_test_headers_sent'],
            $GLOBALS['contentlatch_test_fail_temp_open'],
            $GLOBALS['contentlatch_test_fail_output_write'],
            $GLOBALS['contentlatch_test_buffer_floor'],
            $GLOBALS['contentlatch_test_buffer_not_removable'],
            $GLOBALS['contentlatch_test_buffer_clean_attempts']
        );
        $GLOBALS['contentlatch_test_headers'] = array();
    }

    protected function tearDown(): void
    {
        $_REQUEST = $this->request;
        $_GET     = $this->get;
        $_POST    = $this->post;
        unset(
            $GLOBALS['contentlatch_test_wp_die'],
            $GLOBALS['contentlatch_test_headers'],
            $GLOBALS['contentlatch_test_headers_sent'],
            $GLOBALS['contentlatch_test_fail_temp_open'],
            $GLOBALS['contentlatch_test_fail_output_write'],
            $GLOBALS['contentlatch_test_buffer_floor'],
            $GLOBALS['contentlatch_test_buffer_not_removable'],
            $GLOBALS['contentlatch_test_buffer_clean_attempts']
        );
        parent::tearDown();
    }

    public function testRegistersAuthenticatedAdminPostHookOnly(): void
    {
        if (!function_exists('add_action')) {
            require_once dirname(__DIR__, 2) . '/Support/audit-export-hook.php';
        }

        $before = $this->recordedActions();
        AuditExportController::register($this->service(new InMemoryAuditStore()), new InMemoryRuleRepository());
        $added = array_slice($this->recordedActions(), count($before));
        $hooks = array_map(static fn (array $row): string => (string) $row[0], $added);

        $this->assertContains('admin_post_contentlatch_audit_export_csv', $hooks);
        $this->assertNotContains('admin_post_nopriv_contentlatch_audit_export_csv', $hooks);
        $matched = false;
        foreach ($added as $row) {
            if ($row[0] !== 'admin_post_contentlatch_audit_export_csv') {
                continue;
            }
            $matched = true;
            $this->assertIsArray($row[1]);
            $this->assertSame('export', $row[1][1]);
        }
        $this->assertTrue($matched);

        $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/AuditExportController.php');
        $plugin     = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Plugin.php');
        $this->assertStringContainsString(
            'AuditExportController::register($this->auditService(), $this->ruleRepository())',
            $plugin
        );
        $this->assertStringContainsString("add_action('admin_post_' . self::ACTION", $controller);
        $this->assertStringNotContainsString('admin_post_nopriv_', $controller);
        $this->assertStringContainsString("array(Capabilities::class, 'currentUserCanManage')", $controller);
        $this->assertStringContainsString('wp_verify_nonce($nonce, self::NONCE_ACTION)', $controller);
        $this->assertStringNotContainsString('ContentAuditService::NONCE_ACTION', $controller);
        $this->assertStringNotContainsString('contentlatch_unit_test_', $controller);
        $this->assertStringNotContainsString('countFindings(', $controller);
        $this->assertSame(array('_wpnonce'), $this->requestKeys($controller));
    }

    public function testMissingCapabilityDoesNotReadAuditsOrOpenAStream(): void
    {
        $store    = new CountingAuditStore();
        $verified = false;
        $before   = $this->openStreams();
        $this->allowDownload();
        $controller = new AuditExportController(
            $this->service($store),
            new AuditCsvExporter($store, $this->metadata()),
            static fn (): bool => false,
            static function (string $nonce) use (&$verified): bool {
                unset($nonce);
                $verified = true;

                return true;
            }
        );

        $this->dispatch($controller);

        $this->assertFalse($verified);
        $this->assertSame(0, $store->completeReads);
        $this->assertSame(0, $store->batchReads);
        $this->assertSame($before, $this->openStreams());
        $this->assertRejected('You are not allowed to export ContentLatch audits.', 403);
        $this->assertSame(array(), $GLOBALS['contentlatch_test_headers']);
    }

    public function testMissingNonceDoesNotReadAudits(): void
    {
        $store  = new CountingAuditStore();
        $before = $this->openStreams();
        $this->seed($store, array($this->finding(1, 4, 'Visible finding')));

        $this->dispatch($this->controller($store));
        $this->assertRejected('Missing audit export nonce.', 403);

        $_REQUEST['_wpnonce'] = '';
        unset($GLOBALS['contentlatch_test_wp_die']);
        $this->dispatch($this->controller($store));
        $this->assertRejected('Missing audit export nonce.', 403);

        $_REQUEST['_wpnonce'] = array('ok');
        unset($GLOBALS['contentlatch_test_wp_die']);
        $this->dispatch($this->controller($store));

        $this->assertRejected('Missing audit export nonce.', 403);
        $this->assertSame(0, $store->completeReads);
        $this->assertSame(0, $store->batchReads);
        $this->assertSame($before, $this->openStreams());
        $this->assertSame(array(), $GLOBALS['contentlatch_test_headers']);
    }

    public function testInvalidNonceDoesNotReadAudits(): void
    {
        $store = new CountingAuditStore();
        $this->seed($store, array($this->finding(1, 4, 'Visible finding')));
        $_REQUEST['_wpnonce'] = 'forged';

        $this->dispatch($this->controller($store));

        $this->assertRejected('Invalid audit export nonce.', 403);
        $this->assertSame(0, $store->completeReads);
        $this->assertSame(0, $store->batchReads);
        $this->assertSame(array(), $GLOBALS['contentlatch_test_headers']);
    }

    public function testAuthorizedRequestDownloadsEveryFinding(): void
    {
        $store    = new InMemoryAuditStore();
        $metadata = $this->metadata();
        $this->seed($store, array(
            $this->finding(1, 4, 'First finding', 42),
            $this->finding(2, 4, '=SUM(A1)', 43),
        ));
        $this->seedRun($store, 2, AuditRunStatus::Complete, '2026-01-01 00:00:00', '2026-01-02 00:00:00');
        $store->findings[] = $this->finding(3, 2, 'Historical finding', 99);
        $this->allowDownload();
        $_GET = array(
            'run_id'   => '2',
            'severity' => 'fail',
            'post_type'=> 'page',
            'paged'    => '3',
            'file'     => sys_get_temp_dir() . '/contentlatch-should-not-exist.csv',
            'filename' => '../wp-content/uploads/evil.csv',
        );
        $_POST = array('run_id' => '8');
        $_REQUEST = array_merge($_GET, array('_wpnonce' => 'ok'));

        $expected = $this->rendered($store, $metadata, 4);
        $before   = $this->openStreams();
        $this->expectOutputString($expected);
        $this->dispatch($this->controller($store, metadata: $metadata));

        $this->assertStringStartsWith("\xEF\xBB\xBF\"Post ID\",\"Post Title\"", $expected);
        $this->assertStringContainsString('First finding', $expected);
        $this->assertStringContainsString("'=SUM(A1)", $expected);
        $this->assertStringNotContainsString('Historical finding', $expected);
        $this->assertStringNotContainsString('<html', $expected);
        $this->assertStringNotContainsString('<div', $expected);
        $this->assertArrayNotHasKey('contentlatch_test_wp_die', $GLOBALS);
        $this->assertSame($before, $this->openStreams());
        $this->assertSame(array(
            'Content-Type: text/csv; charset=UTF-8',
            'Content-Disposition: attachment; filename="contentlatch-audit-4-2026-11-07.csv"',
            'X-Content-Type-Options: nosniff',
            'Cache-Control: private, no-store, no-cache, must-revalidate',
            'Pragma: no-cache',
            'Expires: 0',
            'Content-Length: ' . strlen($expected),
        ), $GLOBALS['contentlatch_test_headers']);
        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/contentlatch-should-not-exist.csv');
    }

    public function testNoCompletedAuditIsRejected(): void
    {
        $store = new CountingAuditStore();
        $this->seedRun($store, 3, AuditRunStatus::Running, '2026-11-07 00:00:00', null);
        $store->inner->findings[] = $this->finding(1, 3, 'Running finding');
        $this->allowDownload();

        $this->dispatch($this->controller($store));

        $this->assertRejected('No completed ContentLatch audit is available to export.', 400);
        $this->assertSame(1, $store->completeReads);
        $this->assertSame(0, $store->batchReads);
        $this->assertSame(array(), $GLOBALS['contentlatch_test_headers']);
    }

    public function testLatestEmptyAuditDoesNotFallBackToAnOlderNonemptyRun(): void
    {
        $store  = new CountingAuditStore();
        $before = $this->openStreams();
        $this->seedRun($store, 4, AuditRunStatus::Complete, '2026-11-01 00:00:00', '2026-11-07 15:04:05');
        $this->seed($store, array($this->finding(1, 2, 'OLD_NONEMPTY')), 2, '2026-01-01 00:00:00', '2026-01-02 00:00:00');
        $this->allowDownload();

        $this->dispatch($this->controller($store));

        $this->assertRejected('The latest completed ContentLatch audit has no findings to export.', 400);
        $this->assertSame(1, $store->batchReads);
        $this->assertSame($before, $this->openStreams());
        $this->assertSame(array(), $GLOBALS['contentlatch_test_headers']);
    }

    public function testNewerRunningAuditExportsThePreviousCompletedRun(): void
    {
        $store = new InMemoryAuditStore();
        $this->seed($store, array($this->finding(1, 2, 'COMPLETE_FINDING')), 2);
        $this->seedRun($store, 3, AuditRunStatus::Running, '2026-12-01 00:00:00', null);
        $store->findings[] = $this->finding(2, 3, 'RUNNING_FINDING');
        $this->allowDownload();

        $this->expectOutputRegex('/\A(?s)(?!.*RUNNING_FINDING).*COMPLETE_FINDING.*\z/');
        $this->dispatch($this->controller($store));

        $this->assertSame(
            'Content-Disposition: attachment; filename="contentlatch-audit-2-2026-11-07.csv"',
            $GLOBALS['contentlatch_test_headers'][1]
        );
    }

    public function testNewerFailedOrCancelledAuditDoesNotReplaceTheCompletedRun(): void
    {
        $store = new InMemoryAuditStore();
        $this->seed($store, array($this->finding(1, 2, 'COMPLETE_FINDING')), 2);
        $this->seedRun($store, 5, AuditRunStatus::Failed, '2026-12-01 00:00:00', '2026-12-01 01:00:00', 'Audit batch failed.');
        $this->seedRun($store, 6, AuditRunStatus::Cancelled, '2026-12-02 00:00:00', '2026-12-02 01:00:00');
        $store->findings[] = $this->finding(5, 5, 'FAILED_FINDING');
        $store->findings[] = $this->finding(6, 6, 'CANCELLED_FINDING');
        $this->allowDownload();

        $this->expectOutputRegex('/\A(?s)(?!.*(?:FAILED_FINDING|CANCELLED_FINDING)).*COMPLETE_FINDING.*\z/');
        $this->dispatch($this->controller($store));

        $this->assertStringContainsString(
            'filename="contentlatch-audit-2-2026-11-07.csv"',
            $GLOBALS['contentlatch_test_headers'][1]
        );
    }

    public function testRunIdStaysPinnedWhenANewerCompletedRunAppears(): void
    {
        $store = new PinningAuditStore();
        $this->seed($store, array($this->finding(1, 4, 'ORIGINAL_FINDING')));
        $this->allowDownload();

        $this->expectOutputRegex('/\A(?s)(?!.*NEWER_RUN_FINDING).*ORIGINAL_FINDING.*\z/');
        $this->dispatch($this->controller($store));

        $this->assertStringContainsString(
            'filename="contentlatch-audit-4-2026-11-07.csv"',
            $GLOBALS['contentlatch_test_headers'][1]
        );
    }

    public function testUnsafeCompletionDateFallsBackWithoutUsingRequestText(): void
    {
        $store = new InMemoryAuditStore();
        $this->seed(
            $store,
            array($this->finding(1, 8, 'Dated finding')),
            8,
            '2026-05-02 08:00:00',
            '../../2026-11-07 00:00:00'
        );
        $this->allowDownload();
        $_REQUEST['filename'] = 'contentlatch-audit-1-1999-01-01.csv';

        $this->expectOutputRegex('/Dated finding/');
        $this->dispatch($this->controller($store));

        $this->assertSame(
            'Content-Disposition: attachment; filename="contentlatch-audit-8-2026-05-02.csv"',
            $GLOBALS['contentlatch_test_headers'][1]
        );
    }

    public function testDatabaseFailureIsNotDescribedAsAnEmptyAudit(): void
    {
        $store = new CountingAuditStore();
        $this->seed($store, array($this->finding(1, 4, 'Visible finding')));
        $store->batchFailure = new AuditException('SQLSTATE[HY000] SELECT secret FROM wp_contentlatch_audit_findings');
        $this->allowDownload();
        $before = $this->openStreams();

        $this->dispatch($this->controller($store));

        $this->assertRejected('Could not export the ContentLatch audit.', 500);
        $message = (string) $GLOBALS['contentlatch_test_wp_die']['message'];
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('wp_contentlatch', $message);
        $this->assertStringNotContainsString('no findings', $message);
        $this->assertSame(1, $store->batchReads);
        $this->assertSame($before, $this->openStreams());
        $this->assertSame(array(), $GLOBALS['contentlatch_test_headers']);
    }

    public function testGenerationFailureSendsNoCsvBytes(): void
    {
        $store = new CountingAuditStore();
        $this->seed($store, array($this->finding(1, 4, 'Visible finding')));
        $this->allowDownload();
        $before = $this->openStreams();
        $metadata = new class implements AuditExportMetadata {
            public function post(int $postId): ?array
            {
                unset($postId);
                throw new AuditException('SQLSTATE[HY000] /var/www/html/wp-content/uploads/secret.csv');
            }

            public function rule(int|string $ruleId): ?Rule
            {
                unset($ruleId);

                return null;
            }
        };

        $this->dispatch($this->controller($store, metadata: $metadata));

        $this->assertRejected('Could not export the ContentLatch audit.', 500);
        $message = (string) $GLOBALS['contentlatch_test_wp_die']['message'];
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('/var/www', $message);
        $this->assertStringNotContainsString('secret.csv', $message);
        $this->assertGreaterThan(0, $store->batchReads);
        $this->assertSame($before, $this->openStreams());
        $this->assertSame(array(), $GLOBALS['contentlatch_test_headers']);
    }

    public function testUnavailableTemporaryStreamSendsNoCsv(): void
    {
        $store = new CountingAuditStore();
        $this->seed($store, array($this->finding(1, 4, 'Visible finding')));
        $this->allowDownload();
        $GLOBALS['contentlatch_test_fail_temp_open'] = true;
        $before = $this->openStreams();

        $this->dispatch($this->controller($store));

        $this->assertRejected('Could not export the ContentLatch audit.', 500);
        $this->assertSame(0, $store->batchReads);
        $this->assertSame($before, $this->openStreams());
        $this->assertSame(array(), $GLOBALS['contentlatch_test_headers']);
    }

    public function testOutputWriteFailureDoesNotAppendHtml(): void
    {
        $store = new InMemoryAuditStore();
        $this->seed($store, array($this->finding(1, 4, 'Visible finding')));
        $this->allowDownload();
        $GLOBALS['contentlatch_test_fail_output_write'] = true;
        $before = $this->openStreams();

        $this->dispatch($this->controller($store));

        $this->assertArrayNotHasKey('contentlatch_test_wp_die', $GLOBALS);
        $this->assertNotEmpty($GLOBALS['contentlatch_test_headers']);
        $this->assertSame($before, $this->openStreams());
    }

    public function testAlreadySentHeadersDoNotMixCsvWithHtml(): void
    {
        $store = new InMemoryAuditStore();
        $this->seed($store, array($this->finding(1, 4, 'Visible finding')));
        $this->allowDownload();
        $GLOBALS['contentlatch_test_headers_sent'] = true;

        $this->dispatch($this->controller($store));

        $this->assertArrayNotHasKey('contentlatch_test_wp_die', $GLOBALS);
        $this->assertSame(array(), $GLOBALS['contentlatch_test_headers']);
    }

    public function testBufferedAdminHtmlIsNotPrefixedToTheCsv(): void
    {
        $store    = new InMemoryAuditStore();
        $metadata = $this->metadata();
        $this->seed($store, array($this->finding(1, 4, 'Visible finding')));
        $this->allowDownload();
        $expected = $this->rendered($store, $metadata, 4);

        $this->expectOutputString($expected);
        $floor = ob_get_level();
        ob_start();
        echo '<div id="wpbody" class="wrap"><h1>Audit</h1></div>';
        $this->dispatch($this->controller($store, metadata: $metadata), $floor);

        $this->assertSame($floor, ob_get_level());
    }

    public function testNonRemovableBufferRejectsBeforeCsvHeaders(): void
    {
        $store = new InMemoryAuditStore();
        $this->seed($store, array($this->finding(1, 4, 'Visible finding')));
        $this->allowDownload();
        $floor = ob_get_level();
        ob_start();
        echo '<div id="wpbody">Audit page</div>';
        $GLOBALS['contentlatch_test_buffer_not_removable'] = true;
        $remaining = '';

        try {
            $this->dispatch($this->controller($store), $floor);
        } finally {
            unset($GLOBALS['contentlatch_test_buffer_not_removable']);
            if (ob_get_level() > $floor) {
                $remaining = (string) ob_get_clean();
            }
        }

        $this->assertSame(1, $GLOBALS['contentlatch_test_buffer_clean_attempts']);
        $this->assertSame('<div id="wpbody">Audit page</div>', $remaining);
        $this->assertSame($floor, ob_get_level());
        $this->assertRejected('Could not export the ContentLatch audit.', 500);
        $this->assertSame(array(), $GLOBALS['contentlatch_test_headers']);
    }

    public function testPhpTempSpillIsPrivateAndRemovedOnClose(): void
    {
        $directory = sys_get_temp_dir();
        $before    = glob($directory . '/php*') ?: array();
        $stream    = fopen('php://temp/maxmemory:2097152', 'w+b');
        $this->assertIsResource($stream);
        $payload = str_repeat('x', 2097152 + 64);
        $written = 0;
        while ($written < strlen($payload)) {
            $chunk = fwrite($stream, substr($payload, $written));
            $this->assertNotFalse($chunk);
            $this->assertGreaterThan(0, $chunk);
            $written += $chunk;
        }
        clearstatcache();
        $created = array_values(array_diff(glob($directory . '/php*') ?: array(), $before));
        $stat    = fstat($stream);
        $this->assertCount(1, $created);
        $this->assertStringStartsWith($directory, $created[0]);
        $this->assertDoesNotMatchRegularExpression('/wp-content|uploads/', $created[0]);
        $this->assertIsArray($stat);
        $this->assertSame(strlen($payload), $stat['size']);
        fclose($stream);
        clearstatcache();
        $this->assertFileDoesNotExist($created[0]);
        $this->assertSame($before, glob($directory . '/php*') ?: array());
    }

    public function testOversizedExportReportsItsByteLengthAndLeavesNoSpillFile(): void
    {
        $store    = new InMemoryAuditStore();
        $metadata = $this->metadata();
        $this->seed($store, array($this->finding(1, 4, str_repeat('A', 2_200_000))));
        $this->allowDownload();
        $expected  = $this->rendered($store, $metadata, 4);
        $directory = sys_get_temp_dir();
        $before    = glob($directory . '/php*') ?: array();

        $this->assertGreaterThan(2_097_152, strlen($expected));
        $this->expectOutputString($expected);
        $this->dispatch($this->controller($store, metadata: $metadata));

        $this->assertSame('Content-Length: ' . strlen($expected), $GLOBALS['contentlatch_test_headers'][6]);
        $this->assertSame($before, glob($directory . '/php*') ?: array());
    }

    public function testExportPathDoesNotHookSaveValidation(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/AuditExportController.php');
        $plugin = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Plugin.php');

        $this->assertStringNotContainsString('save_post', $source);
        $this->assertStringNotContainsString('acf/validate_save_post', $source);
        $this->assertStringNotContainsString('rest_pre_insert_', $source);
        $this->assertStringNotContainsString('wp_insert_post_data', $source);
        $this->assertSame(1, substr_count($plugin, 'AuditExportController::register'));
    }

    private function dispatch(AuditExportController $controller, ?int $bufferFloor = null): void
    {
        $GLOBALS['contentlatch_test_buffer_floor'] = $bufferFloor ?? ob_get_level();

        try {
            $controller->export();
        } catch (AdminResponseEnded) {
            return;
        }
    }

    private function allowDownload(): void
    {
        $_REQUEST['_wpnonce'] = 'ok';
    }

    private function assertRejected(string $message, int $status): void
    {
        $this->assertSame(
            array(
                'message' => $message,
                'title'   => 'ContentLatch',
                'args'    => array('response' => $status),
            ),
            $GLOBALS['contentlatch_test_wp_die'] ?? null
        );
    }

    private function controller(
        AuditStoreInterface $store,
        bool $allowed = true,
        ?AuditExportMetadata $metadata = null,
    ): AuditExportController {
        return new AuditExportController(
            $this->service($store),
            new AuditCsvExporter($store, $metadata ?? $this->metadata()),
            static fn (): bool => $allowed,
            static fn (string $nonce): bool => $nonce === 'ok' || $nonce === 'testnonce',
            static function (): void {
                throw new AdminResponseEnded('response ended');
            },
            static function (): int {
                return (int) ($GLOBALS['contentlatch_test_buffer_floor'] ?? 0);
            }
        );
    }

    private function service(AuditStoreInterface $store): ContentAuditService
    {
        $repository = new InMemoryRuleRepository();

        return new ContentAuditService(
            $store,
            new InMemoryAuditPostScanner(),
            new InMemoryAuditLock(),
            $repository,
            new ContentEvaluator($repository, RuleEngine::v1()),
            new AcfIntegration(new AcfFieldCatalog(static fn (): array => array())),
            static fn (): ArrayValueProvider => new ArrayValueProvider(array()),
            static function (): void {
            },
            static fn (): int => 1_700_000_000
        );
    }

    private function metadata(): AuditExportMetadata
    {
        return new class implements AuditExportMetadata {
            public function post(int $postId): ?array
            {
                return array(
                    'title'    => 'Current title ' . $postId,
                    'status'   => 'publish',
                    'edit_url' => 'https://example.test/wp-admin/post.php?post=' . $postId . '&action=edit',
                );
            }

            public function rule(int|string $ruleId): ?Rule
            {
                return RuleFactory::rule(array('id' => $ruleId, 'name' => 'Renamed rule'));
            }
        };
    }

    /**
     * @param AuditFinding[] $findings
     */
    private function seed(
        InMemoryAuditStore|ForwardingAuditStore $store,
        array $findings,
        int $id = 4,
        string $startedAt = '2026-11-01 00:00:00',
        string $finishedAt = '2026-11-07 15:04:05',
    ): void {
        $records = $store instanceof ForwardingAuditStore ? $store->inner : $store;
        $this->seedRun($records, $id, AuditRunStatus::Complete, $startedAt, $finishedAt);
        foreach ($findings as $finding) {
            $records->findings[] = $finding;
        }
    }

    private function seedRun(
        InMemoryAuditStore|ForwardingAuditStore $store,
        int $id,
        AuditRunStatus $status,
        string $startedAt,
        ?string $finishedAt,
        ?string $error = null,
    ): void {
        if ($store instanceof ForwardingAuditStore) {
            $store = $store->inner;
        }
        $store->runs[$id] = new AuditRun(
            $id,
            $status,
            $startedAt,
            $finishedAt,
            $finishedAt ?? $startedAt,
            1,
            0,
            0,
            1,
            0,
            1,
            1,
            1,
            array('recipe'),
            $error
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function finding(
        int $id,
        int $runId,
        string $message,
        int $postId = 42,
        RuleSeverity $severity = RuleSeverity::Fail,
        array $context = array(),
    ): AuditFinding {
        return new AuditFinding(
            $id,
            $runId,
            $postId,
            'recipe',
            12,
            'field_ingredients',
            'v1',
            'required',
            $severity,
            $message,
            '2026-11-07 15:04:05',
            $context
        );
    }

    private function rendered(AuditStoreInterface $store, AuditExportMetadata $metadata, int $runId): string
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        try {
            (new AuditCsvExporter($store, $metadata))->export($runId, $stream);
            rewind($stream);
            $csv = stream_get_contents($stream);
            $this->assertIsString($csv);

            return $csv;
        } finally {
            fclose($stream);
        }
    }

    private function openStreams(): int
    {
        $count = 0;
        foreach (get_resources() as $resource) {
            if (get_resource_type($resource) === 'stream') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private function recordedActions(): array
    {
        $actions = $GLOBALS['contentlatch_test_actions'] ?? array();
        if (class_exists(\ContentLatch\Tests\Unit\DependenciesTest::class)) {
            foreach (\ContentLatch\Tests\Unit\DependenciesTest::$actions as $action) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * @return string[]
     */
    private function requestKeys(string $source): array
    {
        preg_match_all('/\$_(GET|POST|REQUEST|FILES|COOKIE)\s*\[\s*[\'"]([^\'"]+)/', $source, $matches);

        return array_values(array_unique($matches[2]));
    }
}

class ForwardingAuditStore implements AuditStoreInterface
{
    public function __construct(public InMemoryAuditStore $inner = new InMemoryAuditStore())
    {
    }

    public function insertRun(int $actorUserId, array $postTypes, int $postsTotal, string $startedAt): AuditRun
    {
        return $this->inner->insertRun($actorUserId, $postTypes, $postsTotal, $startedAt);
    }

    public function saveRun(AuditRun $run): AuditRun
    {
        return $this->inner->saveRun($run);
    }

    public function findRun(int $id): ?AuditRun
    {
        return $this->inner->findRun($id);
    }

    public function findActiveRun(): ?AuditRun
    {
        return $this->inner->findActiveRun();
    }

    public function findLatestRun(): ?AuditRun
    {
        return $this->inner->findLatestRun();
    }

    public function findLatestCompleteRun(): ?AuditRun
    {
        return $this->inner->findLatestCompleteRun();
    }

    public function replaceFindingsForPosts(int $runId, array $postIds, array $findings): void
    {
        $this->inner->replaceFindingsForPosts($runId, $postIds, $findings);
    }

    public function findFindings(int $runId, ?string $severity = null): array
    {
        return $this->inner->findFindings($runId, $severity);
    }

    public function findFindingsAfterId(int $runId, int $afterId, int $limit): array
    {
        return $this->inner->findFindingsAfterId($runId, $afterId, $limit);
    }

    public function queryFindings(AuditFindingQuery $query): array
    {
        return $this->inner->queryFindings($query);
    }

    public function countFindings(AuditFindingQuery $query): int
    {
        return $this->inner->countFindings($query);
    }

    public function countDistinctPosts(AuditFindingQuery $query): int
    {
        return $this->inner->countDistinctPosts($query);
    }

    public function countFindingsBySeverity(int $runId): array
    {
        return $this->inner->countFindingsBySeverity($runId);
    }

    public function countFindingsByRule(int $runId): array
    {
        return $this->inner->countFindingsByRule($runId);
    }

    public function findRecentRuns(int $limit, int $offset = 0): array
    {
        return $this->inner->findRecentRuns($limit, $offset);
    }

    public function countRuns(): int
    {
        return $this->inner->countRuns();
    }
}

final class CountingAuditStore extends ForwardingAuditStore
{
    public int $completeReads = 0;

    public int $batchReads = 0;

    public ?AuditException $batchFailure = null;

    public function findLatestCompleteRun(): ?AuditRun
    {
        $this->completeReads++;

        return parent::findLatestCompleteRun();
    }

    public function findFindingsAfterId(int $runId, int $afterId, int $limit): array
    {
        $this->batchReads++;
        if ($this->batchFailure !== null) {
            throw $this->batchFailure;
        }

        return parent::findFindingsAfterId($runId, $afterId, $limit);
    }
}

final class PinningAuditStore extends ForwardingAuditStore
{
    private bool $inserted = false;

    public function countFindings(AuditFindingQuery $query): int
    {
        $count = parent::countFindings($query);
        if (!$this->inserted) {
            $this->inserted = true;
            $this->inner->runs[99] = new AuditRun(
                99,
                AuditRunStatus::Complete,
                '2026-12-01 00:00:00',
                '2026-12-02 00:00:00',
                '2026-12-02 00:00:00',
                1,
                0,
                0,
                1,
                0,
                1,
                1,
                1,
                array('post')
            );
            $this->inner->findings[] = new AuditFinding(
                99,
                99,
                7,
                'post',
                12,
                'field_ingredients',
                'v1',
                'required',
                RuleSeverity::Fail,
                'NEWER_RUN_FINDING',
                '2026-12-02 00:00:00'
            );
        }

        return $count;
    }
}
