<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Admin;

use ContentGuard\Admin\AuditPage;
use ContentGuard\Application\Audit\AuditRun;
use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Application\AuditPresentation;
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
