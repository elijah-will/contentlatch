<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class RuleBuilderNoticeScriptTest extends TestCase
{
    public function testBuilderScriptSmoothScrollsAndPreservesTheWarningLabel(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/rules.js');

        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertStringContainsString('behavior: behavior', $js);
        $this->assertStringContainsString('"smooth"', $js);
        $this->assertStringContainsString('"auto"', $js);
        $this->assertStringContainsString('preventScroll: true', $js);
        $this->assertStringContainsString('contentguard-notice-message', $js);
        $this->assertStringContainsString('contentguard-notice-label', $js);
        $this->assertStringContainsString('Warning:', $js);
        $this->assertStringContainsString('#contentguard-rule-notice', $js);
        $this->assertStringContainsString('field.breadcrumb', $js);
        $this->assertStringContainsString('group_label', $js);
        $this->assertStringContainsString('optgroup', $js);
        $this->assertStringContainsString('(every row)', $js);
        $this->assertStringContainsString('(every " + layoutLabel + " row)', $js);
        $this->assertStringContainsString('fieldsForSelect', $js);
        $this->assertStringContainsString('field.container !== "repeater" && field.container !== "flexible_content"', $js);
        $this->assertStringContainsString('fieldOptionId', $js);
        $this->assertStringContainsString('resolution_id', $js);
        $this->assertStringContainsString('data-row', $js);
        $this->assertStringNotContainsString('field_123.field_456', $js);
    }
}
