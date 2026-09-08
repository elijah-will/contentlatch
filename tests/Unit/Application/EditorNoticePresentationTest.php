<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\EditorNoticePresentation;
use PHPUnit\Framework\TestCase;

final class EditorNoticePresentationTest extends TestCase
{
    public function testSingleWarningAndBlockingShareTheSameHierarchy(): void
    {
        $warningHtml = EditorNoticePresentation::noticeHtml(
            EditorNoticePresentation::SEVERITY_WARNING,
            array('Recipe Description — This field is required.')
        );
        $blockingHtml = EditorNoticePresentation::noticeHtml(
            EditorNoticePresentation::SEVERITY_BLOCKING,
            array('Recipe Description — This field is required.')
        );

        $this->assertStringContainsString('ContentGuard · Warning', $warningHtml);
        $this->assertStringContainsString('ContentGuard · Blocking', $blockingHtml);
        $this->assertStringContainsString('Recipe Description — This field is required.', $warningHtml);
        $this->assertStringContainsString('Recipe Description — This field is required.', $blockingHtml);
        $this->assertStringNotContainsString('contentguard-editor-warnings__count', $warningHtml);
        $this->assertStringNotContainsString('contentguard-audit-blockers__count', $blockingHtml);
        $this->assertStringContainsString('contentguard-editor-warnings__title', $warningHtml);
        $this->assertStringContainsString('contentguard-audit-blockers__title', $blockingHtml);

        $this->assertSame(
            "ContentGuard · Warning\nRecipe Description — This field is required.",
            EditorNoticePresentation::noticeText(
                EditorNoticePresentation::SEVERITY_WARNING,
                array('Recipe Description — This field is required.')
            )
        );
        $this->assertSame(
            "ContentGuard · Blocking\nRecipe Description — This field is required.",
            EditorNoticePresentation::noticeText(
                EditorNoticePresentation::SEVERITY_BLOCKING,
                array('Recipe Description — This field is required.')
            )
        );
    }

    public function testMultipleIssuesUseTheSameCountPattern(): void
    {
        $items = array('One', 'Two', 'Three');

        $warningHtml = EditorNoticePresentation::noticeHtml(
            EditorNoticePresentation::SEVERITY_WARNING,
            $items
        );
        $blockingHtml = EditorNoticePresentation::noticeHtml(
            EditorNoticePresentation::SEVERITY_BLOCKING,
            $items
        );

        $this->assertStringContainsString('ContentGuard · Warning', $warningHtml);
        $this->assertStringContainsString('3 warnings', $warningHtml);
        $this->assertStringContainsString('ContentGuard · Blocking', $blockingHtml);
        $this->assertStringContainsString('3 blocking issues', $blockingHtml);

        $this->assertSame(
            "ContentGuard · Warning\n3 warnings\nOne\nTwo\nThree",
            EditorNoticePresentation::noticeText(EditorNoticePresentation::SEVERITY_WARNING, $items)
        );
        $this->assertSame(
            "ContentGuard · Blocking\n3 blocking issues\nOne\nTwo\nThree",
            EditorNoticePresentation::noticeText(EditorNoticePresentation::SEVERITY_BLOCKING, $items)
        );
    }
}
