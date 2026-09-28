<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application;

use ContentLatch\Application\EditorNoticePresentation;
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

        $this->assertStringContainsString('ContentLatch · Warning', $warningHtml);
        $this->assertStringContainsString('ContentLatch · Blocking', $blockingHtml);
        $this->assertStringContainsString('Recipe Description — This field is required.', $warningHtml);
        $this->assertStringContainsString('Recipe Description — This field is required.', $blockingHtml);
        $this->assertStringNotContainsString('contentlatch-editor-warnings__count', $warningHtml);
        $this->assertStringNotContainsString('contentlatch-audit-blockers__count', $blockingHtml);
        $this->assertStringContainsString('contentlatch-editor-warnings__title', $warningHtml);
        $this->assertStringContainsString('contentlatch-audit-blockers__title', $blockingHtml);

        $this->assertSame(
            "ContentLatch · Warning\nRecipe Description — This field is required.",
            EditorNoticePresentation::noticeText(
                EditorNoticePresentation::SEVERITY_WARNING,
                array('Recipe Description — This field is required.')
            )
        );
        $this->assertSame(
            "ContentLatch · Blocking\nRecipe Description — This field is required.",
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

        $this->assertStringContainsString('ContentLatch · Warning', $warningHtml);
        $this->assertStringContainsString('3 warnings', $warningHtml);
        $this->assertStringContainsString('ContentLatch · Blocking', $blockingHtml);
        $this->assertStringContainsString('3 blocking issues', $blockingHtml);

        $this->assertSame(
            "ContentLatch · Warning\n3 warnings\nOne\nTwo\nThree",
            EditorNoticePresentation::noticeText(EditorNoticePresentation::SEVERITY_WARNING, $items)
        );
        $this->assertSame(
            "ContentLatch · Blocking\n3 blocking issues\nOne\nTwo\nThree",
            EditorNoticePresentation::noticeText(EditorNoticePresentation::SEVERITY_BLOCKING, $items)
        );
    }

    public function testIssueLineUsesTheSharedRequiredVocabulary(): void
    {
        $this->assertSame(
            'Title — This field is required.',
            EditorNoticePresentation::issueLine('Title', 'This field is required.', 'required')
        );
        $this->assertSame(
            'Featured Image — This field is required.',
            EditorNoticePresentation::issueLine('Featured Image', 'Featured Image is required.', 'required')
        );
        $this->assertSame(
            'Recipe Description — Keep this unique.',
            EditorNoticePresentation::issueLine('Recipe Description', 'Keep this unique.')
        );
        $this->assertSame(
            'Ingredients are required.',
            EditorNoticePresentation::issueLine('Ingredients', 'Ingredients are required.')
        );
        $this->assertSame(
            'Title — This field is required.',
            EditorNoticePresentation::issueLine('Title', 'Title — This field is required.', 'required')
        );
    }

    public function testBlockingNoticeTextIncludesTitleForASingleIssue(): void
    {
        $this->assertSame(
            "ContentLatch · Blocking\nTitle — This field is required.",
            EditorNoticePresentation::blockingNoticeText(array('Title — This field is required.'))
        );
    }

    public function testBlockingNoticeTextPutsMultipleIssuesOnSeparateLines(): void
    {
        $this->assertSame(
            "ContentLatch · Blocking\n3 blocking issues\n"
            . "Title — This field is required.\n"
            . "Content — This field is required.\n"
            . "Featured Image — This field is required.",
            EditorNoticePresentation::blockingNoticeText(array(
                'Title — This field is required.',
                'Content — This field is required.',
                'Featured Image — This field is required.',
            ))
        );
        $this->assertStringNotContainsString('required..', EditorNoticePresentation::blockingNoticeText(array(
            'Title — This field is required.',
            'Content — This field is required.',
        )));
    }

    public function testCustomMessagesKeepQuotesAndAreEscapedAgainstXss(): void
    {
        $message = 'Can\'t contain the word "chicken" in row 1/5.';
        $line    = EditorNoticePresentation::issueLine('Content', $message);
        $html    = EditorNoticePresentation::noticeHtml(
            EditorNoticePresentation::SEVERITY_BLOCKING,
            array(htmlspecialchars($line, ENT_QUOTES, 'UTF-8'))
        );

        $this->assertSame(
            'Content — Can\'t contain the word "chicken" in row 1/5.',
            $line
        );
        $this->assertStringContainsString('Can&#039;t contain the word &quot;chicken&quot; in row 1/5.', $html);
        $this->assertStringNotContainsString('\\\'', $html);
        $this->assertStringNotContainsString('\\"', $html);

        $scriptLine = EditorNoticePresentation::issueLine('Content', 'Avoid <script>alert(1)</script> & more');
        $script     = EditorNoticePresentation::noticeHtml(
            EditorNoticePresentation::SEVERITY_WARNING,
            array(htmlspecialchars($scriptLine, ENT_QUOTES, 'UTF-8'))
        );
        $this->assertSame('Content — Avoid <script>alert(1)</script> & more', $scriptLine);
        $this->assertStringContainsString('Avoid &lt;script&gt;alert(1)&lt;/script&gt; &amp; more', $script);
        $this->assertStringNotContainsString('<script>', $script);
    }

    public function testNoticeKsesAllowlistKeepsClickTargets(): void
    {
        $allowed = EditorNoticePresentation::allowedNoticeHtml();

        $this->assertArrayHasKey('button', $allowed);
        $this->assertArrayHasKey('data-contentlatch-field', $allowed['button']);
        $this->assertArrayHasKey('data-contentlatch-core', $allowed['button']);
        $this->assertArrayHasKey('data-contentlatch-layout', $allowed['button']);
        $this->assertArrayHasKey('data-contentlatch-display-row', $allowed['button']);
        $this->assertArrayHasKey('data-contentlatch-repeater-path', $allowed['button']);
        $this->assertArrayHasKey('aria-label', $allowed['button']);

        $html = '<button type="button" class="contentlatch-warning-field" data-contentlatch-field="field_description">Title</button>';
        $this->assertSame($html, EditorNoticePresentation::kses($html));
    }
}
