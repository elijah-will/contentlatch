<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\Audit\AuditFinding;
use ContentGuard\Application\EditorAuditIssues;
use ContentGuard\Domain\ContentEvaluation;
use ContentGuard\Domain\EvaluationResult;
use ContentGuard\Domain\EvaluationStatus;
use ContentGuard\Domain\RuleSeverity;
use PHPUnit\Framework\TestCase;

final class EditorAuditIssuesTest extends TestCase
{
    public function testOneBlockingFindingRendersImmediately(): void
    {
        $issues = EditorAuditIssues::fromFindings(
            array($this->finding()),
            42,
            array('15:field_description' => 'Recipe Description')
        );

        $this->assertCount(1, $issues);
        $this->assertSame('Description is required', $issues[0]['message']);
        $this->assertSame('Recipe Description', $issues[0]['label']);
        $this->assertSame('field_description', $issues[0]['fieldKey']);
        $this->assertTrue(EditorAuditIssues::isClickable($issues[0]));

        $html = EditorAuditIssues::classicNoticeHtml($issues);
        $this->assertStringContainsString('ContentGuard', $html);
        $this->assertStringContainsString('Recipe Description', $html);
        $this->assertStringContainsString('Description is required', $html);
        $this->assertStringContainsString('data-contentguard-field="field_description"', $html);
        $this->assertStringNotContainsString('blocking issues', $html);
        $this->assertStringNotContainsString('[object Object]', $html);
    }

    public function testMultipleBlockingFindingsKeepIndependentMessagesAndFields(): void
    {
        $issues = EditorAuditIssues::fromFindings(
            array(
                $this->finding(array(
                    'id'           => 1,
                    'fieldKey'     => 'field_description',
                    'validationId' => 'v1',
                    'message'      => 'Description is required',
                )),
                $this->finding(array(
                    'id'           => 2,
                    'fieldKey'     => 'field_description',
                    'validationId' => 'v2',
                    'code'         => 'min_length',
                    'message'      => 'Must be at least 50 characters',
                )),
                $this->finding(array(
                    'id'           => 3,
                    'fieldKey'     => 'field_yield',
                    'validationId' => 'v3',
                    'message'      => 'Yield is required',
                )),
            ),
            42,
            array(
                '15:field_description' => 'Recipe Description',
                '15:field_yield'       => 'Yield',
            )
        );

        $this->assertCount(3, $issues);
        $this->assertSame(
            array('field_description', 'field_description', 'field_yield'),
            array_column($issues, 'fieldKey')
        );
        $this->assertSame(
            array('Description is required', 'Must be at least 50 characters', 'Yield is required'),
            array_column($issues, 'message')
        );

        $html = EditorAuditIssues::noticeHtml($issues);
        $this->assertStringContainsString('3 blocking issues', $html);
        $this->assertStringContainsString('data-contentguard-field="field_description"', $html);
        $this->assertStringContainsString('data-contentguard-field="field_yield"', $html);
        $this->assertStringContainsString('Go to field: Recipe Description', $html);
        $this->assertStringContainsString('Go to field: Yield', $html);
        $this->assertStringNotContainsString('[object Object]', $html);
        $this->assertStringNotContainsString('[object Object]', EditorAuditIssues::noticeText($issues));
    }

    public function testUnsafeAndMissingFieldKeysStayNonClickable(): void
    {
        $issues = EditorAuditIssues::fromFindings(
            array(
                $this->finding(array(
                    'id'       => 1,
                    'fieldKey' => 'field_nested/path',
                    'message'  => 'Description is required',
                )),
                $this->finding(array(
                    'id'       => 2,
                    'fieldKey' => '',
                    'message'  => 'Something else is required',
                )),
            ),
            42,
            array(
                '15:field_nested/path' => 'Recipe Description',
                '15:'                  => 'Unknown',
            )
        );

        $this->assertSame('', $issues[0]['fieldKey']);
        $this->assertSame('', $issues[1]['fieldKey']);
        $this->assertFalse(EditorAuditIssues::isClickable($issues[0]));
        $this->assertFalse(EditorAuditIssues::isClickable($issues[1]));

        $html = EditorAuditIssues::noticeHtml($issues);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('data-contentguard-field', $html);
        $this->assertStringContainsString('Description is required', $html);
    }

    public function testFindingsForAnotherPostAreNeverDisplayed(): void
    {
        $issues = EditorAuditIssues::fromFindings(
            array(
                $this->finding(array('postId' => 99, 'message' => 'Secret from another post')),
                $this->finding(array('postId' => 42, 'message' => 'Description is required')),
            ),
            42,
            array('15:field_description' => 'Recipe Description')
        );

        $this->assertCount(1, $issues);
        $this->assertSame('Description is required', $issues[0]['message']);
        $this->assertStringNotContainsString('Secret from another post', EditorAuditIssues::noticeText($issues));
    }

    public function testWarningsAreNotDuplicatedIntoTheBlockingNotice(): void
    {
        $issues = EditorAuditIssues::fromFindings(
            array(
                $this->finding(array(
                    'severity' => RuleSeverity::Warning,
                    'message'  => 'This looks thin.',
                )),
                $this->finding(array(
                    'id'      => 2,
                    'message' => 'Description is required',
                )),
            ),
            42,
            array('15:field_description' => 'Recipe Description')
        );

        $this->assertCount(1, $issues);
        $this->assertSame('Description is required', $issues[0]['message']);
    }

    public function testMissingCapabilityOrEditAccessRejectsTheNotice(): void
    {
        $findings = array($this->finding());
        $labels   = array('15:field_description' => 'Recipe Description');

        $this->assertSame(array(), EditorAuditIssues::resolve($findings, 42, $labels, false, true));
        $this->assertSame(array(), EditorAuditIssues::resolve($findings, 42, $labels, true, false));
        $this->assertSame(array(), EditorAuditIssues::resolve($findings, 0, $labels, true, true));
        $this->assertCount(1, EditorAuditIssues::resolve($findings, 42, $labels, true, true));
    }

    public function testRawFieldKeysAreNotUsedAsLabels(): void
    {
        $issues = EditorAuditIssues::fromFindings(
            array($this->finding()),
            42,
            array('15:field_description' => 'field_description')
        );

        $this->assertSame('', $issues[0]['label']);
        $this->assertFalse(EditorAuditIssues::isClickable($issues[0]));
        $this->assertStringNotContainsString('field_description', EditorAuditIssues::issueText($issues[0]));
    }

    public function testLiveEvaluationKeepsOnlyCurrentBlockingIssues(): void
    {
        $evaluation = new ContentEvaluation(
            42,
            \ContentGuard\Domain\ContentStatus::Failed,
            array(
                new EvaluationResult(
                    EvaluationStatus::Failed,
                    15,
                    42,
                    'field_description',
                    'Description is required',
                    RuleSeverity::Fail,
                    'required',
                    array('field_label' => 'Recipe Description')
                ),
                new EvaluationResult(
                    EvaluationStatus::Warning,
                    16,
                    42,
                    'field_title',
                    'Title looks thin.',
                    RuleSeverity::Warning,
                    'required',
                    array('field_label' => 'Title')
                ),
                new EvaluationResult(
                    EvaluationStatus::Passed,
                    17,
                    42,
                    'field_yield',
                    '',
                    RuleSeverity::Fail,
                    'required',
                    array('field_label' => 'Yield')
                ),
                new EvaluationResult(
                    EvaluationStatus::Failed,
                    18,
                    42,
                    'field_ingredients',
                    'Product Information → Ingredients Accordion is required.',
                    RuleSeverity::Fail,
                    'required',
                    array('field_label' => 'Product Information → Ingredients Accordion')
                ),
            )
        );

        $issues = EditorAuditIssues::fromEvaluation($evaluation, 42);

        $this->assertSame(
            array('field_description', 'field_ingredients'),
            array_column($issues, 'fieldKey')
        );
        $this->assertSame('Recipe Description', $issues[0]['label']);
        $this->assertSame('Product Information → Ingredients Accordion', $issues[1]['label']);

        $scoped = EditorAuditIssues::scopedToFieldKeys($issues, array('field_description', 'field_yield'));
        $this->assertCount(1, $scoped);
        $this->assertSame('field_description', $scoped[0]['fieldKey']);

        $empty = EditorAuditIssues::scopedToFieldKeys($issues, array('field_yield'));
        $this->assertSame(array(), $empty);
        $this->assertSame('', EditorAuditIssues::payload($empty)['html']);
        $this->assertSame('', EditorAuditIssues::payload($empty)['text']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function finding(array $overrides = array()): AuditFinding
    {
        return new AuditFinding(
            (int) ($overrides['id'] ?? 1),
            (int) ($overrides['runId'] ?? 7),
            (int) ($overrides['postId'] ?? 42),
            (string) ($overrides['postType'] ?? 'recipe'),
            $overrides['ruleId'] ?? 15,
            (string) ($overrides['fieldKey'] ?? 'field_description'),
            (string) ($overrides['validationId'] ?? 'v1'),
            (string) ($overrides['code'] ?? 'required'),
            $overrides['severity'] ?? RuleSeverity::Fail,
            (string) ($overrides['message'] ?? 'Description is required'),
            (string) ($overrides['createdAt'] ?? '2026-01-01 00:00:00')
        );
    }
}
