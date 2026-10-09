<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit;

require_once dirname(__DIR__) . '/Support/wordpress-admin-functions.php';
require_once dirname(__DIR__) . '/Support/dependency-test-stubs.php';

use ContentLatch\Dependencies;
use ContentLatch\Plugin;
use PHPUnit\Framework\TestCase;

final class DependenciesTest extends TestCase
{
    /** @var list<array{0: string, 1: mixed}> */
    public static array $actions = array();

    public static function recordAction(string $hook, mixed $callback): void
    {
        self::$actions[] = array($hook, $callback);
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$actions = array();
        $GLOBALS['wp_version'] = '6.6.0';
        $GLOBALS['contentlatch_test_can_activate_plugins'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_version'], $GLOBALS['contentlatch_test_can_activate_plugins']);
        self::$actions = array();
        parent::tearDown();
    }

    public function testCanBootIntegrationsRequiresOnlyPhpAndWordPress(): void
    {
        $deps = new Dependencies();
        $this->assertTrue($deps->phpMeetsMinimum());
        $this->assertTrue($deps->wordpressMeetsMinimum());
        $this->assertTrue($deps->canBootIntegrations());
        if (!defined('ACF_VERSION')) {
            $this->assertFalse($deps->acfIsInstalled());
            $this->assertFalse($deps->acfMeetsMinimum());
        }
    }

    public function testCanBootIntegrationsFailsWhenWordPressIsTooOld(): void
    {
        $GLOBALS['wp_version'] = '6.5.0';
        $deps = new Dependencies();
        $this->assertFalse($deps->wordpressMeetsMinimum());
        $this->assertFalse($deps->canBootIntegrations());
    }

    public function testAbsentAcfProducesNoNoticeWhenPhpAndWpAreOk(): void
    {
        if (defined('ACF_VERSION')) {
            $this->markTestSkipped('ACF_VERSION is already defined in this process.');
        }

        $deps = new Dependencies();
        $this->assertSame('', $deps->noticeMessage());
        $deps->registerAdminNotices();
        $this->assertSame(array(), self::$actions);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSupportedAcfVersionProducesNoAcfNotice(): void
    {
        require_once dirname(__DIR__) . '/Support/wordpress-admin-functions.php';
        require_once dirname(__DIR__) . '/Support/dependency-test-stubs.php';
        if (!defined('ACF_VERSION')) {
            define('ACF_VERSION', '6.0.0');
        }
        $GLOBALS['wp_version'] = '6.6.0';
        self::$actions = array();

        $deps = new Dependencies();
        $this->assertTrue($deps->acfIsInstalled());
        $this->assertTrue($deps->acfMeetsMinimum());
        $this->assertTrue($deps->canBootIntegrations());
        $this->assertSame('', $deps->noticeMessage());
        $deps->registerAdminNotices();
        $this->assertSame(array(), self::$actions);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUnsupportedAcfVersionShowsWarningThatCoreRemainsAvailable(): void
    {
        require_once dirname(__DIR__) . '/Support/wordpress-admin-functions.php';
        require_once dirname(__DIR__) . '/Support/dependency-test-stubs.php';
        if (!defined('ACF_VERSION')) {
            define('ACF_VERSION', '5.12.0');
        }
        $GLOBALS['wp_version'] = '6.6.0';
        $GLOBALS['contentlatch_test_can_activate_plugins'] = true;
        self::$actions = array();

        $deps = new Dependencies();
        $this->assertTrue($deps->acfIsInstalled());
        $this->assertFalse($deps->acfMeetsMinimum());
        $this->assertTrue($deps->canBootIntegrations());

        $message = $deps->noticeMessage();
        $this->assertStringContainsString(Plugin::MIN_ACF, $message);
        $this->assertStringContainsString('ACF integration', $message);
        $this->assertStringContainsString('WordPress Core field validation remains available', $message);
        $this->assertSame('notice-warning', $deps->noticeClass());

        $deps->registerAdminNotices();
        $this->assertCount(1, self::$actions);
        $this->assertSame('admin_notices', self::$actions[0][0]);

        ob_start();
        $deps->renderAdminNotice();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString(esc_html($message), $html);
    }

    public function testWordPressTooOldShowsErrorNotice(): void
    {
        $GLOBALS['wp_version'] = '5.0';
        $deps = new Dependencies();
        $this->assertSame('notice-error', $deps->noticeClass());
        $this->assertStringContainsString(Plugin::MIN_WP, $deps->noticeMessage());
        $deps->registerAdminNotices();
        $this->assertCount(1, self::$actions);
        $this->assertSame('admin_notices', self::$actions[0][0]);
    }

    public function testPhpCompatibilityMessageTemplateIsPreserved(): void
    {
        $deps = new Dependencies();
        $this->assertTrue($deps->phpMeetsMinimum());
        $this->assertSame(
            'ContentLatch requires PHP %s or higher.',
            __('ContentLatch requires PHP %s or higher.', 'contentlatch')
        );
    }
}
