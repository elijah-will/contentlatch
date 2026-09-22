<?php
/**
 * Rules admin script enqueue and view inline-script regressions.
 *
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class RulesEnqueueScriptTest extends TestCase
{
    public function testRulesPageLocalizesCatalogAndDeleteConfirmOnRegisteredHandle(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/RulesPage.php');

        $this->assertStringContainsString("wp_register_script(\n            'contentguard-rules'", $php);
        $this->assertStringContainsString("wp_enqueue_script('contentguard-rules')", $php);
        $this->assertStringContainsString("wp_localize_script(\n            'contentguard-rules',\n            'contentguardRules'", $php);
        $this->assertStringContainsString("'catalogFields'", $php);
        $this->assertStringContainsString('catalogFieldsForRequest()', $php);
        $this->assertStringContainsString("'deleteConfirm'", $php);
        $this->assertStringContainsString(
            'Delete this rule? Audit findings for this rule will be kept.',
            $php
        );
        $this->assertStringNotContainsString('wp_add_inline_script', $php);
    }

    public function testRuleEditViewContainsNoScriptTags(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/views/rule-edit.php');

        $this->assertStringNotContainsString('<script', $view);
        $this->assertStringNotContainsString('contentguard-catalog-fields', $view);
        $this->assertStringNotContainsString('contentguardPendingNoticeScroll', $view);
    }

    public function testRuleListItemHasNoInlineOnclick(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/views/partials/rule-list-item.php');

        $this->assertStringNotContainsString('onclick=', $view);
        $this->assertStringNotContainsString('onsubmit=', $view);
        $this->assertStringContainsString('contentguard-delete-rule', $view);
        $this->assertStringNotContainsString('Delete this rule?', $view);
    }

    public function testRulesScriptReadsLocalizedCatalogAndConfirmsDeletes(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/rules.js');

        $this->assertStringContainsString('window.contentguardRules', $js);
        $this->assertStringContainsString('config.catalogFields', $js);
        $this->assertStringContainsString('a.contentguard-delete-rule', $js);
        $this->assertStringContainsString('config.deleteConfirm', $js);
        $this->assertStringContainsString('window.confirm(message)', $js);
        $this->assertStringContainsString('event.preventDefault()', $js);
        $this->assertStringNotContainsString('contentguard-catalog-fields', $js);
        $this->assertStringNotContainsString('getElementById("contentguard-catalog-fields")', $js);
    }

    public function testProductionAdminViewsHaveNoInlineScriptOrEventHandlers(): void
    {
        $root = dirname(__DIR__, 3);
        $paths = array($root . '/contentguard.php');
        foreach (array($root . '/admin/views', $root . '/includes') as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        $failures = array();
        foreach ($paths as $path) {
            $code = (string) file_get_contents($path);
            if (stripos($code, '<script') !== false) {
                $failures[] = $path . ': <script';
            }
            if (stripos($code, '<style') !== false) {
                $failures[] = $path . ': <style';
            }
            if (preg_match('/\son(?:click|change|submit|load|error|focus|blur|input|keydown|keyup|keypress|mouse\w+)\s*=/i', $code) === 1) {
                $failures[] = $path . ': inline event handler';
            }
        }

        $this->assertSame(array(), $failures);
    }
}
