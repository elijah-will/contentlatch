<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\EditorAuditNotice;
use PHPUnit\Framework\TestCase;

final class EditorAuditNoticeTest extends TestCase
{
    public function testNoticeOnlyEnqueuesOnEditorScreens(): void
    {
        $this->assertTrue(EditorAuditNotice::shouldEnqueue('post.php'));
        $this->assertTrue(EditorAuditNotice::shouldEnqueue('post-new.php'));
        $this->assertFalse(EditorAuditNotice::shouldEnqueue('edit.php'));
        $this->assertFalse(EditorAuditNotice::shouldEnqueue('contentguard_page_contentguard-audit'));
    }

    public function testNoticeDoesNotUseSaveValidationHooks(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/EditorAuditNotice.php');

        $this->assertStringContainsString('EditorAuditIssues', $php);
        $this->assertStringContainsString('blockingFindingsForPost', $php);
        $this->assertStringContainsString('evaluateStoredPost', $php);
        $this->assertStringContainsString('editor-blockers', $php);
        $this->assertStringContainsString('preserveAuditRunOnRedirect', $php);
        $this->assertStringNotContainsString('acf_add_validation_error', $php);
        $this->assertStringNotContainsString('acf/validate_save_post', $php);
        $this->assertStringNotContainsString('AcfSaveValidator', $php);
        $this->assertStringNotContainsString('SaveWarningNotifier', $php);
        $this->assertStringNotContainsString('editor-warnings', $php);
    }

    public function testSuccessfulSaveRedirectKeepsTheAuditRun(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/EditorAuditNotice.php');
        $this->assertStringContainsString('redirect_post_location', $php);
        $this->assertStringContainsString('AUDIT_RUN_ARG', $php);
        $this->assertStringContainsString('appendToEditUrl', $php);
        $this->assertStringContainsString('preserveAuditRunOnRedirect', $php);
    }
}
