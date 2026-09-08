<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class EditorFieldCssTest extends TestCase
{
    public function testEditorCssStylesClickableWarningsWithoutFieldHighlight(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/css/editor.css');

        $this->assertStringContainsString('.contentguard-warning-field', $css);
        $this->assertStringContainsString('text-decoration: underline', $css);
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('contentguard-audit-blockers', $css);
        $this->assertStringContainsString('contentguard-editor-warnings', $css);
        $this->assertStringContainsString('contentguard-editor-warnings-notice', $css);
        $this->assertStringNotContainsString('contentguard-field-target', $css);
        $this->assertStringNotContainsString('pointer-events: none', $css);
        $this->assertStringNotContainsString('opacity: 0.5', $css);
    }
}
