<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class EditorFieldCssTest extends TestCase
{
    public function testEditorCssStylesClickableWarningsWithoutFieldHighlight(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/css/editor.css');

        $this->assertStringContainsString('.contentlatch-warning-field', $css);
        $this->assertStringContainsString('text-decoration: underline', $css);
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('contentlatch-audit-blockers', $css);
        $this->assertStringContainsString('contentlatch-editor-warnings', $css);
        $this->assertStringContainsString('contentlatch-editor-warnings-notice', $css);
        $this->assertStringContainsString('.acf-notice.acf-error-message p', $css);
        $this->assertStringContainsString('white-space: pre-line', $css);
        $this->assertStringNotContainsString('contentlatch-field-target', $css);
        $this->assertStringNotContainsString('pointer-events: none', $css);
        $this->assertStringNotContainsString('opacity: 0.5', $css);
    }
}
