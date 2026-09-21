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
        $this->assertStringContainsString('editor-save', $php);
        $this->assertSame(array('SAVE_POST_NOTICE_ID', 'editor-save'), EditorRestBlockNotice::SAVE_NOTICE_IDS);
        $this->assertStringContainsString('is_block_editor', $php);
        $this->assertStringContainsString('contentguard-editor-field', $php);
        $this->assertStringContainsString('EditorFieldFocus::enqueueAssets', $php);
        $this->assertStringNotContainsString('IncomingSaveEvaluator', $php);
        $this->assertStringNotContainsString('RuleEngine', $php);
        $this->assertStringNotContainsString('acf/validate_save_post', $php);
        $this->assertStringNotContainsString('rest_pre_insert', $php);
        $this->assertSame('contentguard_validation_failed', RestSaveValidator::ERROR_CODE);
        $this->assertSame('contentguard-audit-blockers', EditorRestBlockNotice::NOTICE_ID);
    }

    public function testRestBlockersDeclareAcfInputWhenRegistered(): void
    {
        $php = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/Admin/EditorRestBlockNotice.php');

        $this->assertStringContainsString("wp_script_is('acf-input', 'registered')", $php);
        $this->assertStringContainsString("\$deps[] = 'acf-input'", $php);
        $this->assertStringContainsString('wp-api-fetch', $php);
        $this->assertStringContainsString('wp-data', $php);
        $this->assertStringContainsString('wp-i18n', $php);
        $this->assertStringContainsString('contentguard-editor-field', $php);
        $this->assertStringContainsString('validation_complete', $php);
        $this->assertStringContainsString('validation_failure', $php);
        $this->assertStringNotContainsString('acf-pro-input', $php);
        $this->assertStringNotContainsString('setInterval', $php);
        $this->assertStringNotContainsString('acf/validate_save_post', $php);
    }
}
