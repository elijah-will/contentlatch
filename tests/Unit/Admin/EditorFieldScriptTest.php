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
        $this->assertStringContainsString('.acf-clone', $js);
        $this->assertStringContainsString('acf-field-repeater', $js);
        $this->assertStringContainsString('collapse-row', $js);
        $this->assertStringContainsString('.layout[data-layout="', $js);
        $this->assertStringContainsString('collapse-layout', $js);
        $this->assertStringContainsString('acf-flexible-content', $js);
        $this->assertStringContainsString('data-contentguard-layout', $js);
        $this->assertStringContainsString('data-contentguard-display-row', $js);
        $this->assertStringContainsString('findFieldAtDisplayRow', $js);
        $this->assertStringContainsString('realChildLayouts', $js);
        $this->assertStringContainsString('isRealLayout', $js);
        $this->assertStringContainsString('acf-clone', $js);
        $this->assertStringContainsString('.values', $js);
        $this->assertStringContainsString('displayRow', $js);
        $this->assertStringContainsString('(row "', $js);
        $this->assertStringNotContainsString('contentguard_row', $js);
        $this->assertStringNotContainsString('contentguard_layout=', $js);
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
