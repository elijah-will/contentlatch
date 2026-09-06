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
        $this->assertStringContainsString('Back to latest audit', $html);
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

        $this->assertStringContainsString('contentguard-finding__title', $html);
        $this->assertStringContainsString('Chocolate Chip Cookies', $html);
        $this->assertStringContainsString('This field is required when Show &quot;New&quot; Tag is Yes.', $html);
        $this->assertStringContainsString('Recipe Description', $html);
        $this->assertStringNotContainsString('field_123abc', $html);
        $this->assertStringContainsString('Blocking', $html);
        $this->assertStringContainsString('contentguard-finding--blocking', $html);
        $this->assertStringContainsString('contentguard-status__text', $html);
        $this->assertStringContainsString('Rule: New Tag requires description', $html);
        $this->assertStringContainsString('Edit content', $html);
        $this->assertStringContainsString('aria-label="Edit content: Chocolate Chip Cookies"', $html);
        $this->assertStringContainsString('http://example.test/wp-admin/post.php?post=42&amp;action=edit', $html);
        $this->assertStringNotContainsString('>Edit</a>', $html);
        $this->assertStringNotContainsString('Unavailable', $html);
        $this->assertStringNotContainsString(AuditPresentation::filteredEmptyHeading(), $html);
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
        $this->assertStringContainsString('paged=%#%', $html);
        $this->assertStringContainsString('51 issues', $html);
        $this->assertStringContainsString('Chocolate Chip Cookies', $html);
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
        $this->assertStringContainsString('contentguard-audit--history', $html);
        $this->assertStringContainsString('name="run"', $html);
        $this->assertStringContainsString('value="2"', $html);
        $this->assertStringContainsString('Chocolate Chip Cookies', $html);
        $this->assertStringContainsString('Back to latest audit', $html);
        $this->assertStringContainsString('admin.php?page=contentguard-audit', $html);
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
        return new AuditRun(
            $id,
            $status,
            '2026-01-01 00:00:00',
            $status === AuditRunStatus::Complete ? '2026-01-01 00:01:00' : null,
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
