<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\EditorRestBlockNotice;
use ContentGuard\Infrastructure\WordPress\RestSaveValidator;
use PHPUnit\Framework\TestCase;

final class EditorRestBlockNoticeTest extends TestCase
{
    public function testNoticeOnlyEnqueuesOnEditorScreens(): void
    {
        $this->assertTrue(EditorRestBlockNotice::shouldEnqueue('post.php'));
        $this->assertTrue(EditorRestBlockNotice::shouldEnqueue('post-new.php'));
        $this->assertFalse(EditorRestBlockNotice::shouldEnqueue('edit.php'));
        $this->assertFalse(EditorRestBlockNotice::shouldEnqueue('contentguard_page_contentguard-audit'));
    }

    public function testNoticeReusesExistingBlockingChromeAndDoesNotChangeValidation(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/EditorRestBlockNotice.php');

        $this->assertStringContainsString('contentguard-editor-rest-blockers', $php);
        $this->assertStringContainsString('RestSaveValidator::ERROR_CODE', $php);
        $this->assertStringContainsString('contentguard-audit-blockers', $php);
        $this->assertStringContainsString('SAVE_POST_NOTICE_ID', $php);
        $this->assertStringContainsString('is_block_editor', $php);
        $this->assertStringNotContainsString('IncomingSaveEvaluator', $php);
        $this->assertStringNotContainsString('RuleEngine', $php);
        $this->assertStringNotContainsString('acf/validate_save_post', $php);
        $this->assertStringNotContainsString('rest_pre_insert', $php);
        $this->assertSame('contentguard_validation_failed', RestSaveValidator::ERROR_CODE);
        $this->assertSame('contentguard-audit-blockers', EditorRestBlockNotice::NOTICE_ID);
    }
}
