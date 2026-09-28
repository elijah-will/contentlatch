<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class EditorRestBlockersScriptTest extends TestCase
{
    public function testGutenbergRestBlockersReuseBlockingNoticeChrome(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-rest-blockers.js');

        $this->assertStringContainsString('contentlatch_validation_failed', $js);
        $this->assertStringContainsString('isContentLatchError', $js);
        $this->assertStringContainsString('error.code', $js);
        $this->assertStringContainsString('data.failures', $js);
        $this->assertStringContainsString('__("ContentLatch")', $js);
        $this->assertStringContainsString('" · "', $js);
        $this->assertStringContainsString('contentlatch-audit-blockers', $js);
        $this->assertStringContainsString('contentlatch-audit-blockers__title', $js);
        $this->assertStringContainsString('contentlatch-audit-blockers__list', $js);
        $this->assertStringContainsString('html += "<li>" + item + "</li>";', $js);
        $this->assertStringContainsString('data-contentlatch-core', $js);
        $this->assertStringContainsString('data-contentlatch-field', $js);
        $this->assertStringContainsString('failure.repeaterPath', $js);
        $this->assertStringContainsString('data-contentlatch-repeater-path', $js);
        $this->assertStringContainsString('data-contentlatch-layout', $js);
        $this->assertStringContainsString('contentlatch-warning-field', $js);
        $this->assertStringContainsString('isClickableFailure', $js);
        $this->assertStringContainsString('featured_image', $js);
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
        $this->assertStringNotContainsString('contentlatch-editor-warnings', $js);
        $this->assertStringNotContainsString('wp.element.createElement', $js);
        $this->assertStringNotContainsString('contentlatchNavigateToField', $js);
        $this->assertStringNotContainsString('equals', $js);
        $this->assertStringNotContainsString('min_length', $js);
        $this->assertStringNotContainsString('IncomingSaveEvaluator', $js);
        $this->assertStringNotContainsString('RuleEngine', $js);
        $this->assertStringNotContainsString('show_in_rest', $js);
    }

    public function testAcfAjaxValidationRendersTheExistingBlockingNotice(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-rest-blockers.js');
        $field = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-field.js');
        $warnings = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-warnings.js');

        $this->assertStringContainsString('acf.addFilter("validation_complete"', $js);
        $this->assertStringContainsString('acf.addAction("validation_failure"', $js);
        $this->assertStringContainsString('error.contentlatch', $js);
        $this->assertStringContainsString('function contentGuardIssueFromAcfError', $js);
        $this->assertStringContainsString('payload.field || payload.fieldKey', $js);
        $this->assertStringContainsString('collectAcfContentLatchIssues', $js);
        $this->assertStringContainsString('showAcfContentLatchNotice', $js);
        $this->assertStringContainsString('bindAcfValidationHooks.bound', $js);
        $this->assertStringContainsString('DOMContentLoaded', $js);
        $this->assertStringContainsString('buildNotice({', $js);
        $this->assertStringContainsString('data: { failures: failures }', $js);
        $this->assertStringContainsString('__unstableHTML', $js);
        $this->assertStringContainsString('id: NOTICE_ID', $js);
        $this->assertStringContainsString('contentlatch-audit-blockers', $js);
        $this->assertStringContainsString('acf-validation', $js);
        $this->assertStringContainsString('suppressAcfValidationNotice', $js);
        $this->assertStringContainsString('queueAcfValidationNoticeSuppress', $js);
        $this->assertStringContainsString('shownFromAcfValidation', $js);
        $this->assertStringContainsString('contentlatch_validation_failed', $js);
        $this->assertStringContainsString('failure.repeaterPath', $js);
        $this->assertStringContainsString('data-contentlatch-repeater-path', $js);
        $this->assertStringContainsString('data-contentlatch-layout', $js);
        $this->assertStringContainsString('data-contentlatch-field', $js);
        $this->assertStringNotContainsString('innerText', $js);
        $this->assertStringNotContainsString('textContent', $js);
        $this->assertStringNotContainsString('acf-notice', $js);
        $this->assertStringNotContainsString('.acf-error', $js);
        $this->assertStringNotContainsString('show_in_rest', $js);
        $this->assertStringNotContainsString('setInterval', $js);
        $this->assertStringNotContainsString('acf.addFilter("validation_complete"', $field);
        $this->assertStringNotContainsString('error.contentlatch', $field);
        $this->assertStringNotContainsString('acf.addFilter("validation_complete"', $warnings);
        $this->assertStringNotContainsString('error.contentlatch', $warnings);
        $this->assertStringNotContainsString('acf-validation', $warnings);
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
        $this->assertStringContainsString('showContentLatchError(error)', $js);
        $this->assertStringContainsString('wasSaving = isSaving', $js);
        $this->assertStringContainsString('if (!editor || dispatchingNotice)', $js);
        $this->assertStringContainsString('afterCurrentCycle(suppressGutenbergSaveNotice)', $js);
        $this->assertStringContainsString('shouldReplaceNativeSaveNotice', $js);
        $this->assertStringContainsString('shownFromSave', $js);
        $this->assertStringNotContainsString('showContentLatchError(lastSaveError())', $js);
    }

    public function testWarningAndAuditScriptsStayOnTheirOwnNotices(): void
    {
        $warnings = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-warnings.js');
        $audit    = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/js/editor-audit.js');

        $this->assertStringNotContainsString('contentlatch_validation_failed', $warnings);
        $this->assertStringNotContainsString('SAVE_POST_NOTICE_ID', $warnings);
        $this->assertStringContainsString('createNotice("warning"', $warnings);
        $this->assertStringContainsString('contentlatch-editor-warnings', $warnings);
        $this->assertStringNotContainsString('contentlatch_validation_failed', $audit);
        $this->assertStringContainsString('contentlatch-audit-blockers', $audit);
        $this->assertStringContainsString('createNotice("error"', $audit);
    }
}
