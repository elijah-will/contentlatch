<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class EditorFieldScriptTest extends TestCase
{
    public function testEditorFieldScriptTargetsAcfDataKeyAndRespectsReducedMotion(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-field.js');

        $this->assertStringContainsString('.acf-field[data-key="', $js);
        $this->assertStringContainsString('field_[A-Za-z0-9]+', $js);
        $this->assertStringContainsString('acf.addAction', $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertStringContainsString('aria-live', $js);
        $this->assertStringContainsString('postbox.closed', $js);
        $this->assertStringContainsString('openCollapsedAncestors', $js);
        $this->assertStringContainsString('-collapsed', $js);
        $this->assertStringContainsString('contentguardNavigateToField', $js);
        $this->assertStringContainsString('data-contentguard-field', $js);
        $this->assertStringContainsString('autoNavigate', $js);
        $this->assertStringContainsString('scrollIntoView', $js);
        $this->assertStringNotContainsString('contentguard-field-target', $js);
        $this->assertStringNotContainsString('clearHighlight', $js);
        $this->assertStringNotContainsString('4000', $js);
        $this->assertStringNotContainsString('warnings[0]', $js);
    }
}
