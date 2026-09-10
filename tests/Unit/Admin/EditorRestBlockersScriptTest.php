<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class EditorRestBlockersScriptTest extends TestCase
{
    public function testGutenbergRestBlockersReuseBlockingNoticeChrome(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-rest-blockers.js');

        $this->assertStringContainsString('contentguard_validation_failed', $js);
        $this->assertStringContainsString('isContentGuardError', $js);
        $this->assertStringContainsString('error.code', $js);
        $this->assertStringContainsString('data.failures', $js);
        $this->assertStringContainsString('ContentGuard · ', $js);
        $this->assertStringContainsString('contentguard-audit-blockers', $js);
        $this->assertStringContainsString('contentguard-audit-blockers__title', $js);
        $this->assertStringContainsString('contentguard-audit-blockers__list', $js);
        $this->assertStringContainsString('html += "<li>" + item + "</li>";', $js);
        $this->assertStringNotContainsString("error.message + '. '", $js);
        $this->assertStringContainsString('createNotice("error"', $js);
        $this->assertStringContainsString('__unstableHTML', $js);
        $this->assertStringContainsString('id: NOTICE_ID', $js);
        $this->assertStringContainsString('SAVE_POST_NOTICE_ID', $js);
        $this->assertStringContainsString('editor-save', $js);
        $this->assertStringContainsString('getNotices', $js);
        $this->assertStringContainsString('notice.status !== "error"', $js);
        $this->assertStringContainsString('removeNotice(notice.id', $js);
        $this->assertStringContainsString('queueNativeSaveNoticeSuppress', $js);
        $this->assertStringContainsString('nativeSaveErrorNotices', $js);
        $this->assertStringContainsString('didPostSaveRequestSucceed', $js);
        $this->assertStringContainsString('didPostSaveRequestFail', $js);
        $this->assertStringContainsString('isAutosavingPost', $js);
        $this->assertStringContainsString('shownFromSave', $js);
        $this->assertStringContainsString('hideBlockingNotice', $js);
        $this->assertStringContainsString('wp.apiFetch.use', $js);
        $this->assertStringContainsString('Promise.reject(error)', $js);
        $this->assertStringContainsString('This field is required.', $js);
        $this->assertStringContainsString(' — ', $js);
        $this->assertStringNotContainsString('status === 400', $js);
        $this->assertStringNotContainsString('status == 400', $js);
        $this->assertStringNotContainsString('createNotice("warning"', $js);
        $this->assertStringNotContainsString('contentguard-editor-warnings', $js);
        $this->assertStringNotContainsString('wp.element.createElement', $js);
        $this->assertStringNotContainsString('contentguardNavigateToField', $js);
        $this->assertStringNotContainsString('equals', $js);
        $this->assertStringNotContainsString('min_length', $js);
        $this->assertStringNotContainsString('IncomingSaveEvaluator', $js);
        $this->assertStringNotContainsString('RuleEngine', $js);
    }

    public function testNoticeDispatchIsDeferredAndReentrancyGuarded(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-rest-blockers.js');

        $this->assertStringContainsString('function afterCurrentCycle', $js);
        $this->assertStringContainsString('setTimeout(callback, 0)', $js);
        $this->assertStringContainsString('dispatchingNotice', $js);
        $this->assertStringContainsString('lastNoticeHtml', $js);
        $this->assertStringContainsString('afterCurrentCycle(hideBlockingNotice)', $js);
        $this->assertStringContainsString('afterCurrentCycle(function () {', $js);
        $this->assertStringContainsString('showContentGuardError(error)', $js);
        $this->assertStringContainsString('wasSaving = isSaving', $js);
        $this->assertStringContainsString('if (!editor || dispatchingNotice)', $js);
        $this->assertStringContainsString('afterCurrentCycle(suppressGutenbergSaveNotice)', $js);
        $this->assertStringContainsString('shouldReplaceNativeSaveNotice', $js);
        $this->assertStringContainsString('shownFromSave', $js);
        $this->assertStringNotContainsString('showContentGuardError(lastSaveError())', $js);
    }

    public function testWarningAndAuditScriptsStayOnTheirOwnNotices(): void
    {
        $warnings = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-warnings.js');
        $audit    = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-audit.js');

        $this->assertStringNotContainsString('contentguard_validation_failed', $warnings);
        $this->assertStringNotContainsString('SAVE_POST_NOTICE_ID', $warnings);
        $this->assertStringContainsString('createNotice("warning"', $warnings);
        $this->assertStringContainsString('contentguard-editor-warnings', $warnings);
        $this->assertStringNotContainsString('contentguard_validation_failed', $audit);
        $this->assertStringContainsString('contentguard-audit-blockers', $audit);
        $this->assertStringContainsString('createNotice("error"', $audit);
    }
}
