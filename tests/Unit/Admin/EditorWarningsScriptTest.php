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
        $this->assertStringContainsString('data-contentguard-core', $js);
        $this->assertStringContainsString('featured_image', $js);
        $this->assertStringContainsString('warning.fieldKey', $js);
        $this->assertStringContainsString('warning.message', $js);
        $this->assertStringContainsString('warning.label', $js);
        $this->assertStringContainsString('warning.text', $js);
        $this->assertStringContainsString('warning.layout', $js);
        $this->assertStringContainsString('warning.affectedRows', $js);
        $this->assertStringContainsString('data-contentguard-display-row', $js);
        $this->assertStringContainsString('data-contentguard-layout', $js);
        $this->assertStringContainsString('field_[A-Za-z0-9]+', $js);
        $this->assertStringContainsString('payload.warnings', $js);
        $this->assertStringContainsString('didPostSaveRequestSucceed', $js);
        $this->assertStringContainsString('contentguard-editor-warnings', $js);
        $this->assertStringContainsString('ContentGuard · ', $js);
        $this->assertStringContainsString('createNotice("warning", html', $js);
        $this->assertStringContainsString('__unstableHTML', $js);
        $this->assertStringContainsString('spokenMessage', $js);
        $this->assertStringContainsString('typeof html !== "string"', $js);
        $this->assertStringContainsString('[object Object]', $js);
        $this->assertStringContainsString('Go to field:', $js);
        $this->assertStringContainsString('payload.html', $js);
        $this->assertStringContainsString('payload.text', $js);
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
        $this->assertStringNotContainsString('contentguard_row', $js);
    }
}
