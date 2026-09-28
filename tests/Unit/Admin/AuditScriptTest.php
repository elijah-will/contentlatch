<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class AuditScriptTest extends TestCase
{
    public function testAuditScriptUpdatesProgressWithoutChangingEndpoints(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/audit.js');

        $this->assertStringContainsString('contentlatch-audit-start', $js);
        $this->assertStringContainsString('actions.batch', $js);
        $this->assertStringContainsString('actions.start', $js);
        $this->assertStringContainsString('actions.cancel', $js);
        $this->assertStringContainsString('updateProgress', $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertStringContainsString('contentlatch-audit-cancel-confirm', $js);
        $this->assertStringContainsString('contentlatch-history__details', $js);
        $this->assertStringContainsString('aria-expanded', $js);
        $this->assertStringNotContainsString('window.alert', $js);
    }
}
