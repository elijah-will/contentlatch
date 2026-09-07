<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\AuditPage;
use ContentGuard\Application\Audit\AuditFinding;
use ContentGuard\Application\Audit\AuditFindingQuery;
use ContentGuard\Application\Audit\AuditRuleImpact;
use ContentGuard\Application\Audit\AuditRun;
use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Application\AuditPresentation;
use ContentGuard\Domain\RuleSeverity;
use PHPUnit\Framework\TestCase;

final class AuditViewTest extends TestCase
{
    public function testFirstRunStateAndRunAuditIdRemainIntact(): void
    {
        $html = $this->renderAudit($this->baseVars());

        $this->assertStringContainsString('id="contentguard-audit-start"', $html);
        $this->assertStringContainsString('Run Audit', $html);
        $this->assertStringContainsString(AuditPresentation::firstRunHeading(), $html);
        $this->assertStringContainsString(AuditPresentation::firstRunText(), $html);
        $this->assertStringContainsString(AuditPresentation::doesNotModifyContent(), $html);
        $this->assertStringContainsString('Check your existing content against your active rules.', $html);
        $this->assertStringContainsString('id="contentguard-audit-cancel"', $html);
        $this->assertStringContainsString('id="contentguard-audit-active"', $html);
        $this->assertStringContainsString('hidden', $html);
        $this->assertStringNotContainsString('Start Audit', $html);
        $this->assertStringNotContainsString('Statuses:', $html);
        $this->assertStringContainsString('id="contentguard-audit"', $html);
    }

    public function testNoActiveRulesExplainsWhyAuditCannotStart(): void
    {
        $html = $this->renderAudit($this->baseVars(array(
            'postTypes'       => array(),
            'activeRuleCount' => 0,
        )));

        $this->assertStringContainsString(AuditPresentation::noActiveRulesHeading(), $html);
        $this->assertStringContainsString(AuditPresentation::noActiveRulesText(), $html);
        $this->assertStringContainsString('Go to Rules', $html);
        $this->assertStringContainsString('admin.php?page=contentguard', $html);
        $this->assertStringContainsString('id="contentguard-audit-start"', $html);
        $this->assertStringContainsString('disabled="disabled"', $html);
    }

    public function testRunningStateRendersDeterminateProgress(): void
    {
        $active = $this->makeRun(3, AuditRunStatus::Running, array(
            'postsScanned' => 42,
            'postsTotal'   => 137,
            'postsFailed'  => 2,
            'postsWarned'  => 1,
        ));

        $html = $this->renderAudit($this->baseVars(array(
            'active' => $active,
        )));

        $this->assertStringContainsString(AuditPresentation::runningHeading(), $html);
        $this->assertStringContainsString('42 of 137 content items checked', $html);
        $this->assertStringContainsString('id="contentguard-audit-status"', $html);
        $this->assertStringContainsString('id="contentguard-audit-scanned"', $html);
        $this->assertStringContainsString('id="contentguard-audit-progress"', $html);
        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('aria-valuenow="30"', $html);
        $this->assertStringContainsString('Cancel Audit', $html);
        $this->assertStringContainsString('data-run="3"', $html);
        $this->assertStringContainsString(AuditPresentation::cancelConfirmText(), $html);
        $this->assertStringContainsString('Stop audit', $html);
        $this->assertStringContainsString('Keep running', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
    }

    public function testRunningStateOmitsPercentageWhenTotalIsUnknown(): void
    {
        $active = $this->makeRun(4, AuditRunStatus::Running, array(
            'postsScanned' => 8,
            'postsTotal'   => 0,
        ));

        $html = $this->renderAudit($this->baseVars(array(
            'active' => $active,
        )));

        $this->assertStringContainsString('8 content items checked', $html);
        $this->assertStringContainsString('aria-busy="true"', $html);
        $this->assertStringNotContainsString('aria-valuenow', $html);
    }

    public function testCompletedStateAndAllClear(): void
    {
        $complete = $this->makeRun(7, AuditRunStatus::Complete, array(
            'postsScanned' => 12,
            'postsPassed'  => 12,
        ));

        $html = $this->renderAudit($this->baseVars(array(
            'latestComplete' => $complete,
            'latestRun'      => $complete,
            'resultsRun'     => $complete,
        )));

        $this->assertStringContainsString(AuditPresentation::completedHeading(), $html);
        $this->assertStringContainsString(AuditPresentation::allClearHeading(), $html);
        $this->assertStringContainsString(AuditPresentation::allClearText(12), $html);
        $this->assertStringContainsString('Checked', $html);
        $this->assertStringContainsString('Passed', $html);
        $this->assertStringContainsString('Need attention', $html);
        $this->assertStringContainsString('id="contentguard-audit-start"', $html);
    }

    public function testFailedStateShowsSafeMessageAndRetry(): void
    {
        $failed = $this->makeRun(5, AuditRunStatus::Failed, array(
            'errorMessage' => 'Audit batch failed.',
        ));

        $html = $this->renderAudit($this->baseVars(array(
            'latestRun' => $failed,
        )));

        $this->assertStringContainsString(AuditPresentation::failedHeading(), $html);
        $this->assertStringContainsString('Audit batch failed.', $html);
        $this->assertStringContainsString('id="contentguard-audit-retry"', $html);
        $this->assertStringContainsString('Try again', $html);
        $this->assertStringNotContainsString('SQL', $html);
        $this->assertStringNotContainsString('stack', $html);
    }

    public function testHistoricalStateRemainsClearlyIdentified(): void
    {
        $latest = $this->makeRun(9, AuditRunStatus::Complete);
        $older  = $this->makeRun(2, AuditRunStatus::Complete);

        $html = $this->renderAudit($this->baseVars(array(
            'latestComplete' => $latest,
            'latestRun'      => $latest,
            'resultsRun'     => $older,
            'viewingHistory' => true,
        )));

        $this->assertStringContainsString('Previous audit', $html);
        $this->assertStringContainsString(AuditPresentation::historicalNotice(), $html);
        $this->assertStringContainsString(AuditPresentation::viewingResultsFrom('2026-01-01 00:01:00'), $html);
        $this->assertStringContainsString('Back to latest audit', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('contentguard-audit--history', $html);
        $this->assertStringContainsString('admin.php?page=contentguard-audit', $html);
        $this->assertStringNotContainsString('page=contentguard-audit&amp;run=', $html);
        $this->assertStringNotContainsString('name="run"', $html);
    }

    public function testFindingPresentsContentIssueAndAction(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults());

        $this->assertStringContainsString('contentguard-finding__headline', $html);
        $this->assertStringContainsString('contentguard-finding__title', $html);
        $this->assertStringContainsString('Chocolate Chip Cookies', $html);
        $this->assertStringContainsString('This field is required when Show &quot;New&quot; Tag is Yes.', $html);
        $this->assertStringContainsString('contentguard-finding__meta', $html);
        $this->assertStringContainsString('Recipe', $html);
        $this->assertStringContainsString('Recipe Description', $html);
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/css/audit.css');
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
        $this->assertStringContainsString('#contentguard-audit .contentguard-audit-action > p', $css);
        $this->assertStringContainsString('#contentguard-audit .contentguard-page-header__description', $css);
        $this->assertStringContainsString('max-width: none;', $css);
        $this->assertStringNotContainsString('>field_123abc<', $html);
        $this->assertStringContainsString('Blocking', $html);
        $this->assertStringContainsString('contentguard-finding--blocking', $html);
        $this->assertStringContainsString('contentguard-status__text', $html);
        $this->assertStringContainsString('Rule: New Tag requires description', $html);
        $this->assertStringContainsString('Edit content', $html);
        $this->assertStringContainsString('aria-label="Edit content: Chocolate Chip Cookies"', $html);
        $this->assertStringContainsString('http://example.test/wp-admin/post.php?post=42&amp;action=edit', $html);
        $this->assertStringContainsString('contentguard_field=field_123abc', $html);
        $this->assertStringContainsString('contentguard_run=7', $html);
        $this->assertStringContainsString('name="cg_type"', $html);
        $this->assertStringNotContainsString('name="post_type"', $html);
        $this->assertStringNotContainsString('page=contentguard-audit&amp;contentguard_field', $html);
        $this->assertStringNotContainsString('>Edit</a>', $html);
        $this->assertStringNotContainsString('Unavailable', $html);
        $this->assertStringNotContainsString(AuditPresentation::filteredEmptyHeading(), $html);
        $this->assertStringNotContainsString('contentguard-finding--grouped', $html);
    }

    public function testWarningFindingUsesWarningLabelAndModifier(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(8 => 'Tomato Sauce');
        $GLOBALS['contentguard_test_edit_links'] = array(
            8 => 'http://example.test/wp-admin/post.php?post=8&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'severityCounts' => array('fail' => 0, 'warning' => 1),
            'findings'       => array($this->makeFinding(array(
                'postId'   => 8,
                'severity' => RuleSeverity::Warning,
                'message'  => 'Consider adding a serving size.',
            ))),
        )));

        $this->assertStringContainsString('Tomato Sauce', $html);
        $this->assertStringContainsString('Consider adding a serving size.', $html);
        $this->assertStringContainsString('Warning', $html);
        $this->assertStringContainsString('contentguard-finding--warning', $html);
        $this->assertStringContainsString('contentguard-status--caution', $html);
        $this->assertStringNotContainsString('contentguard-finding--blocking', $html);
    }

    public function testDeletedContentAndDeletedRuleStayReadableWithoutBrokenEditLink(): void
    {
        $html = $this->renderAudit($this->completedResults(array(
            'findings'  => array($this->makeFinding(array(
                'postId'  => 77,
                'ruleId'  => 99,
                'message' => 'This field is required.',
            ))),
            'ruleNames' => array(),
            'fieldLabels' => array('99:field_123abc' => 'Recipe Description'),
            'ruleImpacts' => array(new AuditRuleImpact(99, 1, 1)),
        )));

        $this->assertStringContainsString('Content no longer available', $html);
        $this->assertStringContainsString('Deleted rule', $html);
        $this->assertStringContainsString('Recipe Description', $html);
        $this->assertStringContainsString('This field is required.', $html);
        $this->assertStringNotContainsString('Edit content', $html);
        $this->assertStringNotContainsString('post.php?post=77', $html);
        $this->assertStringNotContainsString('href=""', $html);
        $this->assertStringNotContainsString('contentguard_field=', $html);
    }

    public function testZeroFindingsShowsAllClearNotFilteredEmpty(): void
    {
        $complete = $this->makeRun(7, AuditRunStatus::Complete, array(
            'postsScanned' => 12,
            'postsPassed'  => 12,
        ));

        $html = $this->renderAudit($this->baseVars(array(
            'latestComplete' => $complete,
            'latestRun'      => $complete,
            'resultsRun'     => $complete,
        )));

        $this->assertStringContainsString(AuditPresentation::allClearHeading(), $html);
        $this->assertStringContainsString(AuditPresentation::allClearText(12), $html);
        $this->assertStringNotContainsString(AuditPresentation::filteredEmptyHeading(), $html);
        $this->assertStringNotContainsString(AuditPresentation::filteredEmptyText(), $html);
        $this->assertStringNotContainsString('id="contentguard-findings-heading"', $html);
        $this->assertStringNotContainsString('class="contentguard-filters"', $html);
    }

    public function testFilteredEmptyStateExplainsFilters(): void
    {
        $html = $this->renderAudit($this->completedResults(array(
            'findings'     => array(),
            'findingTotal' => 0,
            'affectedPosts'=> 0,
            'query'        => new AuditFindingQuery(7, 'fail'),
        )));

        $this->assertStringContainsString(AuditPresentation::filteredEmptyHeading(), $html);
        $this->assertStringContainsString(AuditPresentation::filteredEmptyText(), $html);
        $this->assertStringContainsString('Clear filters', $html);
        $this->assertStringContainsString('class="contentguard-filters"', $html);
        $this->assertStringContainsString(AuditPresentation::findingsHeading(), $html);
        $this->assertStringNotContainsString(AuditPresentation::allClearHeading(), $html);
        $this->assertStringNotContainsString('Ready to check your content', $html);
    }

    public function testPaginationMarkupRemainsIntact(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'findingTotal' => 51,
            'paged'        => 1,
            'totalPages'   => 2,
            'filterArgs'   => array('page' => AuditPage::SLUG),
        )));

        $this->assertSame(50, AuditPage::PAGE_SIZE);
        $this->assertStringContainsString('contentguard-pagination', $html);
        $this->assertStringContainsString('aria-label="Findings pagination"', $html);
        $this->assertStringContainsString('aria-describedby="contentguard-pagination-status"', $html);
        $this->assertStringContainsString('Showing 1–50 of 51 findings', $html);
        $this->assertStringContainsString('contentguard-pagination__disabled', $html);
        $this->assertStringContainsString('Previous', $html);
        $this->assertStringContainsString('paged=%#%', $html);
        $this->assertStringContainsString('Chocolate Chip Cookies', $html);
    }

    public function testPaginationPreservesFiltersAndHistoricalRun(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'findingTotal' => 127,
            'paged'        => 2,
            'totalPages'   => 3,
            'viewingHistory' => true,
            'query'        => new AuditFindingQuery(2, 'fail'),
            'filterArgs'   => array(
                'page'     => AuditPage::SLUG,
                'run'      => '2',
                'severity' => 'fail',
            ),
        )));

        $this->assertSame(50, AuditPage::PAGE_SIZE);
        $this->assertStringContainsString('Showing 51–100 of 127 findings', $html);
        $this->assertStringContainsString('paged=%#%', $html);
        $this->assertStringContainsString('page=contentguard-audit', $html);
        $this->assertStringContainsString('run=2', $html);
        $this->assertStringContainsString('severity=fail', $html);
        $this->assertStringContainsString('name="run"', $html);
        $this->assertStringNotContainsString('contentguard-pagination__disabled', $html);
    }

    public function testPostTypeFilterUsesSafeQueryArgAndKeepsOtherFilters(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'query'        => new AuditFindingQuery(7, 'fail', '15', 'recipe'),
            'filterArgs'   => array(
                'page'     => AuditPage::SLUG,
                'severity' => 'fail',
                'rule'     => '15',
                'cg_type'  => 'recipe',
            ),
            'findingTotal' => 51,
            'paged'        => 1,
            'totalPages'   => 2,
        )));

        $this->assertStringContainsString('name="cg_type"', $html);
        $this->assertStringContainsString('value="recipe"', $html);
        $this->assertStringNotContainsString('name="post_type"', $html);
        $this->assertStringContainsString('cg_type=recipe', $html);
        $this->assertStringContainsString('severity=fail', $html);
        $this->assertStringContainsString('rule=15', $html);
        $this->assertStringContainsString('page=contentguard-audit', $html);
        $this->assertStringContainsString('contentguard_run=7', $html);
        $this->assertStringContainsString('Clear filters', $html);
        $this->assertStringContainsString('paged=%#%', $html);
    }

    public function testMultipleValidationsGroupOnTheSameContentRuleAndField(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'allFindingTotal' => 2,
            'findingTotal'    => 2,
            'findings'        => array(
                $this->makeFinding(array(
                    'id'           => 11,
                    'validationId' => 'v1',
                    'code'         => 'required',
                    'message'      => 'Page ID is required.',
                )),
                $this->makeFinding(array(
                    'id'           => 12,
                    'validationId' => 'v2',
                    'code'         => 'min_length',
                    'message'      => 'Page ID must be at least 10 characters.',
                )),
            ),
            'fieldLabels' => array('15:field_123abc' => 'Page ID'),
        )));

        $this->assertSame(1, substr_count($html, 'contentguard-finding__title'));
        $this->assertStringContainsString('contentguard-finding--grouped', $html);
        $this->assertStringContainsString('2 issues', $html);
        $this->assertStringContainsString('2 validation issues need attention', $html);
        $this->assertStringNotContainsString('Page ID is required.', $html);
        $this->assertStringNotContainsString('Page ID must be at least 10 characters.', $html);
        $this->assertStringNotContainsString('<ul class="contentguard-finding__issues">', $html);
        $this->assertStringContainsString('contentguard-finding__meta', $html);
        $this->assertStringContainsString('>Page ID</a>', $html);
        $this->assertSame(1, substr_count($html, 'contentguard-finding__action'));
        $this->assertStringContainsString('post.php?post=42&amp;action=edit&amp;contentguard_field=field_123abc&amp;contentguard_run=7', $html);
        $this->assertStringNotContainsString('page=contentguard-audit&amp;contentguard_field', $html);
    }

    public function testMultipleFieldsOnTheSameRuleShareOneCard(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'allFindingTotal' => 2,
            'findingTotal'    => 2,
            'findings'        => array(
                $this->makeFinding(array(
                    'id'           => 21,
                    'fieldKey'     => 'field_pageid',
                    'validationId' => 'v1',
                    'message'      => 'Page ID is required.',
                )),
                $this->makeFinding(array(
                    'id'           => 22,
                    'fieldKey'     => 'field_desc',
                    'validationId' => 'v2',
                    'message'      => 'Description is required.',
                )),
            ),
            'fieldLabels' => array(
                '15:field_pageid' => 'Page ID',
                '15:field_desc'   => 'Recipe Description',
            ),
        )));

        $this->assertSame(1, substr_count($html, 'contentguard-finding__title'));
        $this->assertStringContainsString('contentguard-finding--grouped', $html);
        $this->assertStringContainsString('2 issues', $html);
        $this->assertStringContainsString('2 required fields are missing', $html);
        $this->assertStringNotContainsString('Page ID is required.', $html);
        $this->assertStringNotContainsString('Description is required.', $html);
        $this->assertStringContainsString('contentguard-finding__meta', $html);
        $this->assertStringContainsString('aria-label="Edit content and go to field: Page ID"', $html);
        $this->assertStringContainsString('aria-label="Edit content and go to field: Recipe Description"', $html);
        $this->assertStringContainsString('contentguard_field=field_pageid', $html);
        $this->assertStringContainsString('contentguard_field=field_desc', $html);
        $this->assertStringContainsString('contentguard_run=7', $html);
        $this->assertSame(1, substr_count($html, 'contentguard-finding__action'));
        $this->assertStringNotContainsString('page=contentguard-audit&amp;contentguard_field', $html);
    }

    public function testFourRequiredFieldsStayOnOneCompactCard(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Fajita-Stuffed Chicken');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $fields = array(
            'field_prep'  => 'Prep Time',
            'field_cook'  => 'Cook Time',
            'field_total' => 'Total Time',
            'field_yield' => 'Yield',
        );
        $findings = array();
        $labels   = array();
        $id       = 50;
        foreach ($fields as $key => $label) {
            $findings[] = $this->makeFinding(array(
                'id'       => $id++,
                'fieldKey' => $key,
                'message'  => 'This field is required.',
            ));
            $labels['15:' . $key] = $label;
        }

        $html = $this->renderAudit($this->completedResults(array(
            'allFindingTotal' => 4,
            'findingTotal'    => 4,
            'findings'        => $findings,
            'fieldLabels'     => $labels,
        )));

        $this->assertSame(1, substr_count($html, 'contentguard-finding__title'));
        $this->assertStringContainsString('4 issues', $html);
        $this->assertStringContainsString('4 required fields are missing', $html);
        $this->assertStringContainsString('Prep Time', $html);
        $this->assertStringContainsString('Cook Time', $html);
        $this->assertStringContainsString('Total Time', $html);
        $this->assertStringContainsString('Yield', $html);
        $this->assertSame(1, substr_count($html, 'contentguard-finding__issue'));
        $this->assertStringNotContainsString('This field is required.', $html);
        $this->assertStringNotContainsString('contentguard-finding__field-name', $html);
    }

    public function testManyFieldsCollapseWithAMoreCount(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $findings = array();
        $labels   = array();
        for ($i = 1; $i <= 8; $i++) {
            $key = 'field_f' . $i;
            $findings[] = $this->makeFinding(array(
                'id'       => 60 + $i,
                'fieldKey' => $key,
                'message'  => 'This field is required.',
            ));
            $labels['15:' . $key] = 'Field ' . $i;
        }

        $html = $this->renderAudit($this->completedResults(array(
            'allFindingTotal' => 8,
            'findingTotal'    => 8,
            'findings'        => $findings,
            'fieldLabels'     => $labels,
        )));

        $this->assertStringContainsString('8 issues', $html);
        $this->assertStringContainsString('8 required fields are missing', $html);
        $this->assertStringContainsString('+ 3 more', $html);
        $this->assertStringContainsString('Affected fields: Field 1, Field 2, Field 3, Field 4, Field 5, Field 6, Field 7, Field 8', $html);
        $this->assertSame(5, substr_count($html, 'class="contentguard-finding__field"'));
    }

    public function testUnsafeFieldKeyStaysVisibleButNotALink(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'findings'    => array($this->makeFinding(array('fieldKey' => 'not a key'))),
            'fieldLabels' => array('15:not a key' => 'Recipe Description'),
        )));

        $this->assertStringContainsString('Recipe Description', $html);
        $this->assertStringNotContainsString('aria-label="Edit content and go to field: Recipe Description"', $html);
        $this->assertStringNotContainsString('>field_', $html);
        $this->assertStringContainsString('contentguard_run=7', $html);
        $this->assertStringNotContainsString('contentguard_field=', $html);
    }

    public function testMixedSeverityGroupAnnouncesBothStatuses(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'allFindingTotal' => 2,
            'findingTotal'    => 2,
            'findings'        => array(
                $this->makeFinding(array(
                    'id'       => 31,
                    'fieldKey' => 'field_prep',
                    'message'  => 'Prep Time is required.',
                )),
                $this->makeFinding(array(
                    'id'       => 32,
                    'fieldKey' => 'field_total',
                    'severity' => RuleSeverity::Warning,
                    'message'  => 'Total Time looks thin.',
                )),
            ),
            'fieldLabels' => array(
                '15:field_prep'  => 'Prep Time',
                '15:field_total' => 'Total Time',
            ),
        )));

        $this->assertStringContainsString('2 issues', $html);
        $this->assertStringContainsString('1 Blocking', $html);
        $this->assertStringContainsString('1 Warning', $html);
        $this->assertStringContainsString('contentguard-status--danger', $html);
        $this->assertStringContainsString('contentguard-status--caution', $html);
        $this->assertStringContainsString('2 validation issues need attention', $html);
        $this->assertStringContainsString('Prep Time', $html);
        $this->assertStringContainsString('Total Time', $html);
        $this->assertStringNotContainsString('Prep Time is required.', $html);
        $this->assertStringNotContainsString('Total Time looks thin.', $html);
    }

    public function testSeverityFilterDoesNotClaimHiddenWarnings(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'query'        => new AuditFindingQuery(7, 'fail'),
            'findings'     => array($this->makeFinding(array(
                'fieldKey' => 'field_prep',
                'message'  => 'Prep Time is required.',
            ))),
            'fieldLabels'  => array('15:field_prep' => 'Prep Time'),
        )));

        $this->assertStringContainsString('Prep Time is required.', $html);
        $this->assertStringContainsString('Blocking', $html);
        $this->assertStringNotContainsString('contentguard-finding--grouped', $html);
        $this->assertStringNotContainsString('Total Time looks thin.', $html);
        $this->assertStringNotContainsString('1 Warning', $html);
    }

    public function testDifferentRulesOnTheSameContentStaySeparateCards(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'allFindingTotal' => 2,
            'findingTotal'    => 2,
            'findings'        => array(
                $this->makeFinding(array(
                    'id'      => 41,
                    'ruleId'  => 15,
                    'message' => 'Description is required.',
                )),
                $this->makeFinding(array(
                    'id'      => 42,
                    'ruleId'  => 22,
                    'message' => 'Yield is required.',
                )),
            ),
            'ruleNames'   => array(
                '15' => 'Recipes requirements',
                '22' => 'Yield rules',
            ),
            'fieldLabels' => array(
                '15:field_123abc' => 'Recipe Description',
                '22:field_123abc' => 'Yield',
            ),
        )));

        $this->assertSame(2, substr_count($html, 'contentguard-finding__title'));
        $this->assertStringContainsString('Rule: Recipes requirements', $html);
        $this->assertStringContainsString('Rule: Yield rules', $html);
        $this->assertStringNotContainsString('contentguard-finding--grouped', $html);
    }

    public function testEditContentRejectsAnAuditPageUrl(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/admin.php?page=contentguard-audit&post_type=recipe',
        );

        $html = $this->renderAudit($this->completedResults());

        $this->assertStringContainsString('Chocolate Chip Cookies', $html);
        $this->assertStringNotContainsString('Edit content', $html);
        $this->assertStringNotContainsString('page=contentguard-audit&amp;contentguard_field', $html);
    }

    public function testUnsafeFieldKeyDoesNotChangeTheEditUrl(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'findings'    => array($this->makeFinding(array('fieldKey' => 'not a key'))),
            'fieldLabels' => array('15:not a key' => 'Recipe Description'),
        )));

        $this->assertStringContainsString('http://example.test/wp-admin/post.php?post=42&amp;action=edit&amp;contentguard_run=7', $html);
        $this->assertStringContainsString('Edit content', $html);
        $this->assertStringNotContainsString('contentguard_field=', $html);
    }

    public function testHistoricalFindingsStayTiedToThatAudit(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $latest = $this->makeRun(9, AuditRunStatus::Complete);
        $older  = $this->makeRun(2, AuditRunStatus::Complete, array(
            'postsScanned' => 12,
            'postsFailed'  => 1,
        ));

        $html = $this->renderAudit($this->completedResults(array(
            'latestComplete' => $latest,
            'latestRun'      => $latest,
            'resultsRun'     => $older,
            'viewingHistory' => true,
            'query'          => new AuditFindingQuery(2),
            'findings'       => array($this->makeFinding(array('runId' => 2))),
        )));

        $this->assertStringContainsString('Previous audit', $html);
        $this->assertStringContainsString(AuditPresentation::historicalNotice(), $html);
        $this->assertStringContainsString(AuditPresentation::viewingResultsFrom('2026-01-01 00:01:00'), $html);
        $this->assertStringContainsString('contentguard-audit--history', $html);
        $this->assertStringContainsString('name="run"', $html);
        $this->assertStringContainsString('value="2"', $html);
        $this->assertStringContainsString('contentguard_run=2', $html);
        $this->assertStringNotContainsString('contentguard_run=9', $html);
        $this->assertStringContainsString('Chocolate Chip Cookies', $html);
        $this->assertStringContainsString('Back to latest audit', $html);
        $this->assertStringContainsString('admin.php?page=contentguard-audit', $html);
    }

    public function testFindingsFiltersPresentAsAToolbarWithSelectedState(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $html = $this->renderAudit($this->completedResults(array(
            'query'       => new AuditFindingQuery(7, 'fail', '15', 'recipe'),
            'ruleImpacts' => array(new AuditRuleImpact(15, 1, 1)),
            'filterArgs'  => array(
                'page'     => AuditPage::SLUG,
                'severity' => 'fail',
                'rule'     => '15',
                'cg_type'  => 'recipe',
            ),
        )));

        $this->assertStringContainsString('class="contentguard-filters"', $html);
        $this->assertStringContainsString('#contentguard-findings-heading', $html);
        $this->assertStringContainsString('scroll-margin-top: 48px', (string) file_get_contents(dirname(__DIR__, 3) . '/admin/css/audit.css'));
        $this->assertStringContainsString('role="group"', $html);
        $this->assertStringContainsString('aria-labelledby="contentguard-filter-severity-label"', $html);
        $this->assertStringContainsString(AuditPresentation::severityFilterLabel(), $html);
        $this->assertStringContainsString(AuditPresentation::ruleFilterLabel(), $html);
        $this->assertStringContainsString(AuditPresentation::contentTypeFilterLabel(), $html);
        $this->assertStringContainsString('name="severity"', $html);
        $this->assertStringContainsString('name="rule"', $html);
        $this->assertStringContainsString('name="cg_type"', $html);
        $this->assertStringNotContainsString('name="post_type"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('value="fail"', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
        $this->assertStringContainsString('is-selected', $html);
        $this->assertStringContainsString('selected="selected"', $html);
        $this->assertStringContainsString('value="recipe"', $html);
        $this->assertStringContainsString('value="15"', $html);
        $this->assertStringContainsString('Showing 1–1 of 1 findings', $html);
        $this->assertStringContainsString('contentguard-filters__actions', $html);
        $this->assertStringContainsString('Filter', $html);
        $this->assertStringContainsString('Clear filters', $html);
        $this->assertStringNotContainsString('name="paged"', $html);
        $this->assertMatchesRegularExpression('/<button[^>]+name="severity"[^>]+value="fail"[^>]*aria-pressed="true"/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*[?&]severity=/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*[?&](?:rule|cg_type)=/', $html);
    }

    public function testSeverityChipsSubmitImmediatelyAndResetFindingsPage(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $all = $this->renderAudit($this->completedResults(array(
            'query' => new AuditFindingQuery(7),
        )));
        $this->assertMatchesRegularExpression('/<button[^>]+name="severity"[^>]+value=""[^>]*aria-pressed="true"/', $all);
        $this->assertMatchesRegularExpression('/<button[^>]+name="severity"[^>]+value="fail"[^>]*aria-pressed="false"/', $all);
        $this->assertMatchesRegularExpression('/<button[^>]+name="severity"[^>]+value="warning"[^>]*aria-pressed="false"/', $all);

        $blocking = $this->renderAudit($this->completedResults(array(
            'query' => new AuditFindingQuery(7, 'fail'),
            'paged' => 3,
        )));
        $this->assertMatchesRegularExpression('/<button[^>]+name="severity"[^>]+value="fail"[^>]*aria-pressed="true"/', $blocking);
        $this->assertMatchesRegularExpression('/<button[^>]+name="severity"[^>]+value=""[^>]*aria-pressed="false"/', $blocking);
        $this->assertStringContainsString('type="submit"', $blocking);
        $this->assertStringNotContainsString('name="paged"', $blocking);

        $warning = $this->renderAudit($this->completedResults(array(
            'query' => new AuditFindingQuery(7, 'warning'),
            'severityCounts' => array('fail' => 0, 'warning' => 1),
            'findings' => array($this->makeFinding(array('severity' => RuleSeverity::Warning))),
        )));
        $this->assertMatchesRegularExpression('/<button[^>]+name="severity"[^>]+value="warning"[^>]*aria-pressed="true"/', $warning);
        $this->assertMatchesRegularExpression('/<button[^>]+name="severity"[^>]+value="fail"[^>]*aria-pressed="false"/', $warning);
    }

    public function testSeverityChipFormPreservesHistoricalRun(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $latest = $this->makeRun(9, AuditRunStatus::Complete);
        $older  = $this->makeRun(2, AuditRunStatus::Complete);

        $html = $this->renderAudit($this->completedResults(array(
            'latestComplete' => $latest,
            'latestRun'      => $latest,
            'resultsRun'     => $older,
            'viewingHistory' => true,
            'query'          => new AuditFindingQuery(2, 'warning'),
            'findings'       => array($this->makeFinding(array('runId' => 2))),
            'historyPaged'   => 2,
        )));

        $this->assertStringContainsString('name="run"', $html);
        $this->assertStringContainsString('value="2"', $html);
        $this->assertStringContainsString('name="hpaged"', $html);
        $this->assertStringContainsString('name="severity"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('contentguard_run=2', $html);
        $this->assertStringNotContainsString('name="paged"', $html);
        $this->assertStringContainsString('admin.php?page=contentguard-audit&amp;run=2', $html);
        $this->assertStringNotContainsString('page=contentguard-audit&amp;run=2&amp;severity=', $html);
    }

    public function testAuditHistoryIdentifiesCurrentHistoricalFailedAndCancelledRuns(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $current = $this->makeRun(9, AuditRunStatus::Complete, array(
            'postsScanned' => 127,
            'postsFailed'  => 2,
            'postsWarned'  => 1,
            'finishedAt'   => '2026-09-06 12:00:00',
        ));
        $older = $this->makeRun(4, AuditRunStatus::Complete, array(
            'postsScanned' => 124,
            'postsFailed'  => 3,
            'postsWarned'  => 2,
            'finishedAt'   => '2026-09-05 15:42:00',
        ));
        $failed = $this->makeRun(3, AuditRunStatus::Failed, array(
            'postsScanned' => 40,
            'finishedAt'   => '2026-09-04 09:00:00',
            'errorMessage' => 'Audit timed out',
        ));
        $cancelled = $this->makeRun(2, AuditRunStatus::Cancelled, array(
            'postsScanned' => 18,
            'finishedAt'   => '2026-09-03 08:00:00',
        ));

        $html = $this->renderAudit($this->completedResults(array(
            'latestComplete' => $current,
            'latestRun'      => $current,
            'resultsRun'     => $older,
            'viewingHistory' => true,
            'query'          => new AuditFindingQuery(4),
            'findings'       => array($this->makeFinding(array('runId' => 4))),
            'history'        => array($current, $older, $failed, $cancelled),
        )));

        $this->assertStringContainsString('contentguard-history', $html);
        $this->assertStringContainsString(AuditPresentation::historyHeading(), $html);
        $this->assertStringNotContainsString('widefat', $html);
        $this->assertStringContainsString(AuditPresentation::currentAuditLabel(), $html);
        $this->assertStringContainsString('contentguard-history__item--current', $html);
        $this->assertStringContainsString('contentguard-history__item--viewing', $html);
        $this->assertStringContainsString('aria-current="true"', $html);
        $this->assertStringContainsString('127 content items checked', $html);
        $this->assertStringContainsString('124 content items checked', $html);
        $this->assertStringContainsString('2 need attention · 1 need review', $html);
        $this->assertStringContainsString('3 need attention · 2 need review', $html);
        $this->assertStringContainsString('Completed', $html);
        $this->assertStringContainsString('Failed', $html);
        $this->assertStringContainsString('Cancelled', $html);
        $this->assertStringContainsString('Audit timed out', $html);
        $this->assertStringContainsString(AuditPresentation::cancelledText(), $html);
        $this->assertStringContainsString('contentguard-history__item--failed', $html);
        $this->assertStringContainsString('contentguard-history__item--cancelled', $html);
        $this->assertStringContainsString(AuditPresentation::viewResultsLabel(), $html);
        $this->assertStringContainsString('contentguard-history__viewing', $html);
        $this->assertStringContainsString('>' . AuditPresentation::viewingAuditLabel() . '<', $html);
        $this->assertStringContainsString(AuditPresentation::viewingResultsFrom('2026-09-05 15:42:00'), $html);
        $this->assertStringContainsString(AuditPresentation::backToLatestLabel(), $html);
        $this->assertStringContainsString('contentguard_run=4', $html);
        $this->assertStringNotContainsString('contentguard_run=9', $html);
        $this->assertStringContainsString('admin.php?page=contentguard-audit', $html);
        $this->assertStringNotContainsString('page=contentguard-audit&amp;run=4">View results', $html);
        $this->assertStringNotContainsString('page=contentguard-audit&amp;run=9', $html);
        $this->assertStringNotContainsString('page=contentguard-audit&amp;run=3', $html);
        $this->assertStringNotContainsString('page=contentguard-audit&amp;run=2', $html);
    }

    public function testLatestHistoryItemLinksBackWithoutARunQuery(): void
    {
        $current = $this->makeRun(9, AuditRunStatus::Complete, array(
            'postsScanned' => 127,
            'postsFailed'  => 1,
            'finishedAt'   => '2026-09-06 12:00:00',
        ));
        $older = $this->makeRun(4, AuditRunStatus::Complete, array(
            'postsScanned' => 124,
            'finishedAt'   => '2026-09-05 15:42:00',
        ));

        $html = $this->renderAudit($this->completedResults(array(
            'latestComplete' => $current,
            'latestRun'      => $current,
            'resultsRun'     => $current,
            'history'        => array($current, $older),
        )));

        $this->assertStringContainsString(AuditPresentation::currentAuditLabel(), $html);
        $this->assertStringContainsString(AuditPresentation::viewResultsLabel(), $html);
        $this->assertStringContainsString('admin.php?page=contentguard-audit&amp;run=4', $html);
        $this->assertStringNotContainsString('page=contentguard-audit&amp;run=9', $html);
        $this->assertStringNotContainsString('contentguard-history__viewing', $html);
        $this->assertStringNotContainsString('contentguard-audit--history', $html);
        $this->assertStringNotContainsString('aria-current="true"', $html);
    }

    public function testEmptyStatesStayVisuallyConsistent(): void
    {
        $first = $this->renderAudit($this->baseVars());
        $this->assertStringContainsString(AuditPresentation::firstRunHeading(), $first);
        $this->assertStringContainsString(AuditPresentation::firstRunText(), $first);
        $this->assertStringContainsString(AuditPresentation::historyEmptyText(), $first);
        $this->assertStringContainsString('contentguard-empty', $first);

        $none = $this->renderAudit($this->baseVars(array(
            'postTypes'       => array(),
            'activeRuleCount' => 0,
        )));
        $this->assertStringContainsString(AuditPresentation::noActiveRulesHeading(), $none);
        $this->assertStringContainsString('Go to Rules', $none);

        $complete = $this->makeRun(7, AuditRunStatus::Complete, array(
            'postsScanned' => 12,
            'postsPassed'  => 12,
        ));
        $clear = $this->renderAudit($this->baseVars(array(
            'latestComplete' => $complete,
            'latestRun'      => $complete,
            'resultsRun'     => $complete,
        )));
        $this->assertStringContainsString(AuditPresentation::allClearHeading(), $clear);
        $this->assertStringContainsString(AuditPresentation::allClearText(12), $clear);
        $this->assertStringContainsString('contentguard-empty', $clear);
    }

    public function testAuditHistoryUsesFullWidthAndPaginatesIndependently(): void
    {
        $GLOBALS['contentguard_test_titles']     = array(42 => 'Chocolate Chip Cookies');
        $GLOBALS['contentguard_test_edit_links'] = array(
            42 => 'http://example.test/wp-admin/post.php?post=42&action=edit',
        );

        $current = $this->makeRun(40, AuditRunStatus::Complete, array('postsScanned' => 127));
        $page    = array();
        for ($id = 40; $id >= 31; $id--) {
            $page[] = $this->makeRun($id, AuditRunStatus::Complete, array('postsScanned' => 10 + $id));
        }

        $first = $this->renderAudit($this->completedResults(array(
            'latestComplete'   => $current,
            'latestRun'        => $current,
            'resultsRun'       => $current,
            'history'          => $page,
            'historyTotal'     => 37,
            'historyPaged'     => 1,
            'historyTotalPages'=> 4,
            'historyArgs'      => array('page' => AuditPage::SLUG, 'paged' => '3'),
            'paged'            => 3,
            'totalPages'       => 3,
            'findingTotal'     => 127,
            'filterArgs'       => array(
                'page'     => AuditPage::SLUG,
                'severity' => 'fail',
                'hpaged'   => '1',
            ),
        )));

        $this->assertSame(10, AuditPage::HISTORY_PAGE_SIZE);
        $this->assertSame(10, count($page));
        $this->assertStringContainsString('contentguard-history--wide', $first);
        $this->assertStringContainsString('contentguard-pagination--history', $first);
        $this->assertStringContainsString('aria-label="Audit history pagination"', $first);
        $this->assertStringContainsString('Showing 1–10 of 37 audits', $first);
        $this->assertStringContainsString('hpaged=%#%', $first);
        $this->assertStringContainsString('paged=3', $first);
        $this->assertStringContainsString('paged=%#%', $first);
        $this->assertStringContainsString('severity=fail', $first);
        $this->assertStringContainsString('contentguard-pagination--findings', $first);
        $this->assertStringContainsString('Showing 101–127 of 127 findings', $first);

        $older = $this->makeRun(4, AuditRunStatus::Complete, array('postsScanned' => 124));
        $middleHistory = $page;
        $middleHistory[9] = $older;

        $middle = $this->renderAudit($this->completedResults(array(
            'latestComplete'   => $current,
            'latestRun'        => $current,
            'resultsRun'       => $older,
            'history'          => $middleHistory,
            'historyTotal'     => 37,
            'historyPaged'     => 2,
            'historyTotalPages'=> 4,
            'historyArgs'      => array('page' => AuditPage::SLUG, 'run' => '4', 'paged' => '3'),
            'viewingHistory'   => true,
            'query'            => new AuditFindingQuery(4),
            'findings'         => array($this->makeFinding(array('runId' => 4))),
            'paged'            => 3,
            'totalPages'       => 3,
            'findingTotal'     => 127,
            'filterArgs'       => array(
                'page'   => AuditPage::SLUG,
                'run'    => '4',
                'hpaged' => '2',
            ),
        )));

        $this->assertStringContainsString('Showing 11–20 of 37 audits', $middle);
        $this->assertStringContainsString('name="hpaged"', $middle);
        $this->assertStringContainsString('value="2"', $middle);
        $this->assertStringContainsString('name="run"', $middle);
        $this->assertStringContainsString('contentguard_run=4', $middle);
        $this->assertStringContainsString('hpaged=2', $middle);
        $this->assertStringContainsString('paged=3', $middle);
        $this->assertStringContainsString('contentguard-history__viewing', $middle);
        $this->assertStringContainsString(AuditPresentation::currentAuditLabel(), $middle);
        $this->assertStringContainsString('hpaged=2', $middle);

        $last = $this->renderAudit($this->completedResults(array(
            'latestComplete'   => $current,
            'latestRun'        => $current,
            'resultsRun'       => $current,
            'history'          => array_slice($page, 0, 7),
            'historyTotal'     => 37,
            'historyPaged'     => 4,
            'historyTotalPages'=> 4,
            'historyArgs'      => array('page' => AuditPage::SLUG),
        )));

        $this->assertStringContainsString('Showing 31–37 of 37 audits', $last);
        $this->assertStringContainsString('contentguard-pagination__disabled', $last);
        $this->assertStringContainsString('Next', $last);
        $this->assertStringContainsString('hpaged=%#%', $last);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['contentguard_test_titles'], $GLOBALS['contentguard_test_edit_links']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function completedResults(array $overrides = array()): array
    {
        $complete = $this->makeRun(7, AuditRunStatus::Complete, array(
            'postsScanned' => 12,
            'postsPassed'  => 10,
            'postsFailed'  => 1,
            'postsWarned'  => 1,
        ));

        return $this->baseVars(array_merge(
            array(
                'latestComplete'   => $complete,
                'latestRun'        => $complete,
                'resultsRun'       => $complete,
                'allFindingTotal'  => 1,
                'allAffectedPosts' => 1,
                'findingTotal'     => 1,
                'affectedPosts'    => 1,
                'severityCounts'   => array('fail' => 1, 'warning' => 0),
                'findings'         => array($this->makeFinding()),
                'ruleNames'        => array('15' => 'New Tag requires description'),
                'fieldLabels'      => array('15:field_123abc' => 'Recipe Description'),
            ),
            $overrides
        ));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeFinding(array $overrides = array()): AuditFinding
    {
        return new AuditFinding(
            (int) ($overrides['id'] ?? 1),
            (int) ($overrides['runId'] ?? 7),
            (int) ($overrides['postId'] ?? 42),
            (string) ($overrides['postType'] ?? 'recipe'),
            $overrides['ruleId'] ?? 15,
            (string) ($overrides['fieldKey'] ?? 'field_123abc'),
            (string) ($overrides['validationId'] ?? 'v1'),
            (string) ($overrides['code'] ?? 'required'),
            $overrides['severity'] ?? RuleSeverity::Fail,
            (string) ($overrides['message'] ?? 'This field is required when Show "New" Tag is Yes.'),
            (string) ($overrides['createdAt'] ?? '2026-01-01 00:00:00')
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseVars(array $overrides = array()): array
    {
        return array_merge(
            array(
                'active'           => null,
                'latestComplete'   => null,
                'latestRun'        => null,
                'resultsRun'       => null,
                'viewingHistory'   => false,
                'query'            => null,
                'findings'         => array(),
                'findingTotal'     => 0,
                'affectedPosts'    => 0,
                'severityCounts'   => array('fail' => 0, 'warning' => 0),
                'allFindingTotal'  => 0,
                'allAffectedPosts' => 0,
                'postTypes'        => array('recipe'),
                'activeRuleCount'  => 2,
                'ruleImpacts'      => array(),
                'ruleNames'        => array(),
                'fieldLabels'      => array(),
                'history'          => array(),
                'historyTotal'     => 0,
                'historyPaged'     => 1,
                'historyTotalPages'=> 1,
                'historyArgs'      => array('page' => AuditPage::SLUG),
                'paged'            => 1,
                'totalPages'       => 1,
                'filterArgs'       => array('page' => AuditPage::SLUG),
            ),
            $overrides
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeRun(int $id, AuditRunStatus $status, array $overrides = array()): AuditRun
    {
        $finishedAt = array_key_exists('finishedAt', $overrides)
            ? $overrides['finishedAt']
            : ($status === AuditRunStatus::Complete ? '2026-01-01 00:01:00' : null);

        return new AuditRun(
            $id,
            $status,
            (string) ($overrides['startedAt'] ?? '2026-01-01 00:00:00'),
            is_string($finishedAt) || $finishedAt === null ? $finishedAt : null,
            '2026-01-01 00:00:00',
            (int) ($overrides['postsScanned'] ?? 0),
            (int) ($overrides['postsPassed'] ?? 0),
            (int) ($overrides['postsWarned'] ?? 0),
            (int) ($overrides['postsFailed'] ?? 0),
            0,
            (int) ($overrides['postsTotal'] ?? 0),
            0,
            1,
            array('recipe'),
            $overrides['errorMessage'] ?? ($status === AuditRunStatus::Failed ? 'Audit batch failed.' : null)
        );
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function renderAudit(array $vars): string
    {
        require_once dirname(__DIR__, 2) . '/Support/wordpress-admin-functions.php';
        extract($vars, EXTR_SKIP);
        $view = CONTENTGUARD_DIR . 'admin/views/audit.php';
        ob_start();
        require $view;

        return (string) ob_get_clean();
    }
}
