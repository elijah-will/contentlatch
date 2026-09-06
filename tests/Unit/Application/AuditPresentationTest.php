<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\AuditPresentation;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AuditPresentationTest extends TestCase
{
    public function testRuleNameFallsBackWhenTheRuleIsMissing(): void
    {
        $rule = RuleFactory::rule(array('name' => 'Sauces require ingredients'));

        $this->assertSame('Sauces require ingredients', AuditPresentation::ruleName($rule, 10));
        $this->assertSame('Deleted rule', AuditPresentation::ruleName(null, 10));
    }

    public function testFieldLabelPrefersSnapshotThenKey(): void
    {
        $rule = RuleFactory::rule(array(
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => RuleFactory::field('field_description', 'recipe_description', 'Recipe Description'),
                )),
            ),
        ));

        $this->assertSame('Recipe Description', AuditPresentation::fieldLabel($rule, 'field_description'));
        $this->assertSame('field_missing', AuditPresentation::fieldLabel($rule, 'field_missing'));
        $this->assertSame('field_description', AuditPresentation::fieldLabel(null, 'field_description'));

        $conditionOnly = RuleFactory::rule(array(
            'conditions' => array(
                RuleFactory::condition(array(
                    'field' => RuleFactory::field('field_signature', 'is_signature', 'Is Signature'),
                )),
            ),
            'validations' => array(),
        ));
        $this->assertSame('Is Signature', AuditPresentation::fieldLabel($conditionOnly, 'field_signature'));
    }

    public function testPostTitleAndSeverityStayReadable(): void
    {
        $this->assertSame('Tomato Sauce', AuditPresentation::postTitle('Tomato Sauce'));
        $this->assertSame('Content no longer available', AuditPresentation::postTitle(''));
        $this->assertSame('Blocking', AuditPresentation::severityLabel(RuleSeverity::Fail));
        $this->assertSame('Warning', AuditPresentation::severityLabel(RuleSeverity::Warning));
    }

    public function testAuditStateCopyAndProgressLabels(): void
    {
        $this->assertSame('Ready to check your content', AuditPresentation::firstRunHeading());
        $this->assertStringContainsString('does not change your content', AuditPresentation::doesNotModifyContent());
        $this->assertSame('Audit in progress', AuditPresentation::runningHeading());
        $this->assertSame('42 of 137 content items checked', AuditPresentation::progressLabel(42, 137));
        $this->assertSame('8 content items checked', AuditPresentation::progressLabel(8, 0));
        $this->assertSame('Audit completed', AuditPresentation::completedHeading());
        $this->assertSame('All clear', AuditPresentation::allClearHeading());
        $this->assertSame(
            'No issues were found in this audit.',
            AuditPresentation::allClearText(12)
        );
        $this->assertSame(
            'This audit completed with no eligible content and no issues.',
            AuditPresentation::allClearText(0)
        );
        $this->assertSame('Viewing results from September 5, 2026', AuditPresentation::viewingResultsFrom('September 5, 2026'));
        $this->assertSame('Viewing historical audit results', AuditPresentation::viewingResultsFrom(''));
        $this->assertSame('Back to latest audit', AuditPresentation::backToLatestLabel());
        $this->assertSame('Current', AuditPresentation::currentAuditLabel());
        $this->assertSame('Viewing', AuditPresentation::viewingAuditLabel());
        $this->assertSame('View results', AuditPresentation::viewResultsLabel());
        $this->assertSame('Audit History', AuditPresentation::historyHeading());
        $this->assertSame('12 content items checked', AuditPresentation::contentItemsCheckedLabel(12));
        $this->assertSame('3 need attention · 2 need review', AuditPresentation::historyContentOutcomeLabel(3, 2));
        $this->assertSame('Severity', AuditPresentation::severityFilterLabel());
        $this->assertSame('Content type', AuditPresentation::contentTypeFilterLabel());
        $this->assertSame('Audit failed', AuditPresentation::failedHeading());
        $this->assertSame('Audit cancelled', AuditPresentation::cancelledHeading());
        $this->assertStringContainsString('not used as the latest completed result', AuditPresentation::cancelledText());
        $this->assertSame('Issues', AuditPresentation::findingsHeading());
        $this->assertSame('Edit content', AuditPresentation::editContentLabel());
        $this->assertSame('Edit content: Tomato Sauce', AuditPresentation::editContentAria('Tomato Sauce'));
        $this->assertSame('Edit content: Content no longer available', AuditPresentation::editContentAria(''));
        $this->assertSame('No matching findings', AuditPresentation::filteredEmptyHeading());
        $this->assertSame('Try changing or clearing your filters.', AuditPresentation::filteredEmptyText());
        $this->assertSame('Showing 1–50 of 127 findings', AuditPresentation::findingsRangeLabel(1, 50, 127));
        $this->assertSame('Showing 51–100 of 127 findings', AuditPresentation::findingsRangeLabel(2, 50, 127));
        $this->assertSame('Showing 101–127 of 127 findings', AuditPresentation::findingsRangeLabel(3, 50, 127));
        $this->assertSame('Findings pagination', AuditPresentation::paginationLabel());
        $this->assertSame('Showing 1–10 of 37 audits', AuditPresentation::historyRangeLabel(1, 10, 37));
        $this->assertSame('Showing 11–20 of 37 audits', AuditPresentation::historyRangeLabel(2, 10, 37));
        $this->assertSame('Showing 31–37 of 37 audits', AuditPresentation::historyRangeLabel(4, 10, 37));
        $this->assertSame('Audit history pagination', AuditPresentation::historyPaginationLabel());
        $this->assertSame('1 issue', AuditPresentation::issuesCountLabel(1));
        $this->assertSame('2 issues', AuditPresentation::issuesCountLabel(2));
        $this->assertSame('1 Blocking', AuditPresentation::blockingCountLabel(1));
        $this->assertSame('2 Blocking', AuditPresentation::blockingCountLabel(2));
        $this->assertSame('1 Warning', AuditPresentation::warningCountLabel(1));
        $this->assertSame('3 Warning', AuditPresentation::warningCountLabel(3));
        $this->assertSame('Edit content and go to field: Prep Time', AuditPresentation::goToFieldEditAria('Prep Time'));
        $this->assertSame('Prep Time', AuditPresentation::displayFieldLabel('Prep Time', 'field_prep'));
        $this->assertSame('', AuditPresentation::displayFieldLabel('field_prep', 'field_prep'));
        $this->assertSame('', AuditPresentation::displayFieldLabel('field_abc123', 'field_other'));
        $this->assertSame('4 required fields are missing', AuditPresentation::groupSummary(array(
            'This field is required.',
            'This field is required.',
            'This field is required.',
            'This field is required.',
        ), 4));
        $this->assertSame('2 required fields are missing', AuditPresentation::groupSummary(array(
            'Page ID is required.',
            'Description is required.',
        ), 2));
        $this->assertSame('2 validation issues need attention', AuditPresentation::groupSummary(array(
            'This field is required.',
            'Must be at least 50 characters.',
        ), 2));
        $this->assertSame('This field is required.', AuditPresentation::groupSummary(array(
            'This field is required.',
        ), 1));
        $this->assertSame('+ 4 more', AuditPresentation::moreFieldsLabel(4));
        $this->assertTrue(AuditPresentation::isRequiredMessage('This field is required.'));
        $this->assertTrue(AuditPresentation::isRequiredMessage('Prep Time is required'));
        $this->assertFalse(AuditPresentation::isRequiredMessage('This field is required when Show "New" Tag is Yes.'));
    }
}
