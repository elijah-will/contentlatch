<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class EditorWarningsScriptTest extends TestCase
{
    public function testGutenbergWarningsUseReadablePropertiesAndPerFieldNavigation(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-warnings.js');

        $this->assertStringContainsString('contentguardNavigateToField', $js);
        $this->assertStringContainsString('data-contentguard-field', $js);
        $this->assertStringContainsString('warning.fieldKey', $js);
        $this->assertStringContainsString('warning.message', $js);
        $this->assertStringContainsString('warning.label', $js);
        $this->assertStringContainsString('warning.text', $js);
        $this->assertStringContainsString('field_[A-Za-z0-9]+', $js);
        $this->assertStringContainsString('payload.warnings', $js);
        $this->assertStringContainsString('didPostSaveRequestSucceed', $js);
        $this->assertStringContainsString('contentguard-warning-', $js);
        $this->assertStringContainsString('__unstableHTML', $js);
        $this->assertStringContainsString('spokenMessage', $js);
        $this->assertStringContainsString('typeof content !== "string"', $js);
        $this->assertStringContainsString('[object Object]', $js);
        $this->assertStringContainsString('Go to field:', $js);
        $this->assertStringNotContainsString('wp.element.createElement', $js);
        $this->assertStringNotContainsString('JSON.stringify', $js);
        $this->assertStringNotContainsString('contentguardEditorField.fieldKey', $js);
        $this->assertStringNotContainsString('config.fieldKey', $js);
        $this->assertStringNotContainsString('warnings[0].fieldKey', $js);
        $this->assertStringNotContainsString('createNotice("warning", warning', $js);
        $this->assertStringNotContainsString("createNotice('warning', warning", $js);
        $this->assertStringContainsString('refreshFromRest', $js);
        $this->assertStringContainsString('isAutosavingPost', $js);
        $this->assertStringNotContainsString('contentguard-audit-blockers', $js);
        $this->assertStringNotContainsString('editor-blockers', $js);
    }
}
