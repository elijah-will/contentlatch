<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class EditorAuditScriptTest extends TestCase
{
    public function testGutenbergAuditNoticeUsesSafeStringHtml(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-audit.js');

        $this->assertStringContainsString('contentguardEditorAudit', $js);
        $this->assertStringContainsString('typeof config.html === "string"', $js);
        $this->assertStringContainsString('typeof config.text === "string"', $js);
        $this->assertStringContainsString('__unstableHTML', $js);
        $this->assertStringContainsString('createNotice("error"', $js);
        $this->assertStringContainsString('removeNotice', $js);
        $this->assertStringContainsString('refreshFromRest', $js);
        $this->assertStringContainsString('didPostSaveRequestSucceed', $js);
        $this->assertStringContainsString('isAutosavingPost', $js);
        $this->assertStringContainsString('config.restPath', $js);
        $this->assertStringContainsString('[object Object]', $js);
        $this->assertStringNotContainsString('wp.element.createElement', $js);
        $this->assertStringNotContainsString('JSON.stringify', $js);
        $this->assertStringNotContainsString('createNotice("error", config', $js);
        $this->assertStringNotContainsString('contentguardNavigateToField', $js);
        $this->assertStringNotContainsString('equals', $js);
        $this->assertStringNotContainsString('min_length', $js);
        $this->assertStringContainsString('contentguard-audit-blockers', $js);
        $this->assertStringNotContainsString('contentguard-warning-', $js);
        $this->assertStringNotContainsString('createNotice("warning"', $js);
    }
}
