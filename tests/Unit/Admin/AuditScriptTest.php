<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class AuditScriptTest extends TestCase
{
    public function testAuditScriptUpdatesProgressWithoutChangingEndpoints(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/audit.js');

        $this->assertStringContainsString('contentguard-audit-start', $js);
        $this->assertStringContainsString('actions.batch', $js);
        $this->assertStringContainsString('actions.start', $js);
        $this->assertStringContainsString('actions.cancel', $js);
        $this->assertStringContainsString('updateProgress', $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertStringContainsString('contentguard-audit-cancel-confirm', $js);
        $this->assertStringContainsString('contentguard-history__details', $js);
        $this->assertStringContainsString('aria-expanded', $js);
        $this->assertStringNotContainsString('window.alert', $js);
    }
}
