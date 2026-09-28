<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Admin;

use ContentLatch\Admin\AuditAdminRequest;
use ContentLatch\Admin\AuditPage;
use ContentLatch\Application\Audit\AuditFindingQuery;
use ContentLatch\Application\Audit\AuditRun;
use ContentLatch\Application\Audit\AuditRunStatus;
use PHPUnit\Framework\TestCase;

final class AuditPageTest extends TestCase
{
    public function testStartRequiresActiveRulePostTypesButNotEligiblePosts(): void
    {
        $this->assertFalse(AuditPage::canStart(null, array()));
        $this->assertTrue(AuditPage::canStart(null, array('recipe')));

        $active = $this->makeRun(1, AuditRunStatus::Running);

        $this->assertFalse(AuditPage::canStart($active, array('recipe')));
    }

    public function testResultsRunAcceptsOnlyCompleteRequestedRuns(): void
    {
        $complete = $this->makeRun(4, AuditRunStatus::Complete);
        $older    = $this->makeRun(2, AuditRunStatus::Complete);
        $failed   = $this->makeRun(5, AuditRunStatus::Failed);
        $cancelled = $this->makeRun(6, AuditRunStatus::Cancelled);

        $this->assertSame($complete, AuditPage::resolveResultsRun($complete, null));
        $this->assertSame($older, AuditPage::resolveResultsRun($complete, $older));
        $this->assertSame($complete, AuditPage::resolveResultsRun($complete, $failed));
        $this->assertSame($complete, AuditPage::resolveResultsRun($complete, $cancelled));
        $this->assertNull(AuditPage::resolveResultsRun(null, $failed));
        $this->assertSame($complete, AuditPage::resolveResultsRun($complete, $this->makeRun(9, AuditRunStatus::Running)));
    }

    public function testFindingQueryFromRequestSanitizesFiltersAndPages(): void
    {
        $this->assertSame(0, AuditPage::requestedRunId(array()));
        $this->assertSame(12, AuditPage::requestedRunId(array('run' => '12')));

        $query = AuditPage::findingQueryFromRequest(
            7,
            array(
                'severity' => 'fail',
                'rule'     => '15',
                'cl_type'  => 'recipe',
                'paged'    => '3',
            ),
            50
        );

        $this->assertSame(7, $query->runId);
        $this->assertSame('fail', $query->severity);
        $this->assertSame('15', $query->ruleId);
        $this->assertSame('recipe', $query->postType);
        $this->assertSame(50, $query->limit);
        $this->assertSame(100, $query->offset);
        $this->assertSame(3, AuditPage::currentPage($query));

        $legacy = AuditPage::findingQueryFromRequest(7, array('post_type' => 'page'));
        $this->assertSame('page', $legacy->postType);
        $this->assertSame('recipe', AuditPage::requestPostType(array(
            'cl_type'   => 'recipe',
            'post_type' => 'page',
        )));

        $ignored = AuditPage::findingQueryFromRequest(7, array(
            'severity' => 'all',
            'rule'     => '0',
            'paged'    => '-2',
        ));
        $this->assertFalse($ignored->hasFilters());
        $this->assertSame(0, $ignored->offset);
    }

    public function testFilterArgsKeepSafeValuesAndHistoryRun(): void
    {
        $this->assertSame(
            array(
                'page'     => AuditPage::SLUG,
                'run'      => '4',
                'severity' => 'warning',
                'rule'     => '8',
                'cl_type'  => 'page',
            ),
            AuditPage::filterArgs(
                array(
                    'severity'  => 'warning',
                    'rule'      => '8',
                    'post_type' => 'page',
                    'paged'     => '2',
                ),
                4
            )
        );

        $this->assertSame(
            array('page' => AuditPage::SLUG, 'cl_type' => 'recipe'),
            AuditPage::filterArgs(array('cl_type' => 'recipe'))
        );
        $this->assertArrayNotHasKey('post_type', AuditPage::filterArgs(array('post_type' => 'recipe')));
        $this->assertSame(
            array('page' => AuditPage::SLUG),
            AuditPage::filterArgs(array('severity' => 'nope', 'rule' => '0'))
        );
        $this->assertSame(
            array('page' => AuditPage::SLUG, 'hpaged' => '2'),
            AuditPage::filterArgs(array('hpaged' => '2'))
        );
        $this->assertArrayNotHasKey('paged', AuditPage::filterArgs(array('paged' => '3', 'hpaged' => '2')));
        $this->assertArrayNotHasKey('hpaged', AuditPage::filterArgs(array('hpaged' => '1')));
        $this->assertSame(1, AuditPage::requestedHistoryPage(array()));
        $this->assertSame(1, AuditPage::requestedHistoryPage(array('hpaged' => '-2')));
        $this->assertSame(3, AuditPage::requestedHistoryPage(array('hpaged' => '3')));
        $this->assertSame(4, AuditPage::clampPage(9, 4));
        $this->assertSame(1, AuditPage::clampPage(0, 4));
        $this->assertSame(
            array('page' => AuditPage::SLUG, 'paged' => '3'),
            AuditPage::historyPaginationArgs(array('page' => AuditPage::SLUG, 'hpaged' => '2'), 3)
        );
        $this->assertSame(
            array('page' => AuditPage::SLUG, 'run' => '4'),
            AuditPage::clearFilterArgs(4)
        );
        $this->assertSame(
            array('page' => AuditPage::SLUG, 'run' => '4', 'hpaged' => '2'),
            AuditPage::clearFilterArgs(4, 2)
        );
        $this->assertArrayNotHasKey('severity', AuditPage::clearFilterArgs(4, 2));
        $this->assertSame(10, AuditPage::HISTORY_PAGE_SIZE);
        $this->assertSame('hpaged', AuditPage::HISTORY_PAGED_ARG);
        $this->assertTrue(AuditAdminRequest::reservedPostTypeWouldBreakAuditPage(array(
            'page'      => AuditPage::SLUG,
            'post_type' => 'recipe',
        )));
    }

    public function testTotalPagesNeverDropsBelowOne(): void
    {
        $this->assertSame(1, AuditPage::totalPages(0));
        $this->assertSame(1, AuditPage::totalPages(50));
        $this->assertSame(2, AuditPage::totalPages(51));
        $this->assertSame(3, AuditPage::currentPage(new AuditFindingQuery(1, null, null, null, 20, 40)));
    }

    public function testFormatRunTimeFallsBackWithoutWordPress(): void
    {
        $this->assertSame('', AuditPage::formatRunTime(null));
        $this->assertSame('2026-09-05 15:42:00', AuditPage::formatRunTime('2026-09-05 15:42:00'));
    }

    public function testHistoricalViewIsPreviousAuditAndBackLinkOmitsRun(): void
    {
        $latest = $this->makeRun(4, AuditRunStatus::Complete);
        $older  = $this->makeRun(2, AuditRunStatus::Complete);
        $failed = $this->makeRun(5, AuditRunStatus::Failed);

        $this->assertTrue(AuditPage::isViewingHistory($latest, $older));
        $this->assertFalse(AuditPage::isViewingHistory($latest, $latest));
        $this->assertFalse(AuditPage::isViewingHistory($latest, AuditPage::resolveResultsRun($latest, $failed)));
        $this->assertSame('Previous audit', AuditPage::healthHeading(true));
        $this->assertSame('Audit completed', AuditPage::healthHeading(false));
        $this->assertTrue(AuditPage::isFirstRun(null, null));
        $this->assertFalse(AuditPage::isFirstRun($latest, null));
        $this->assertFalse(AuditPage::isFirstRun(null, $this->makeRun(8, AuditRunStatus::Running)));
        $this->assertSame(array('page' => AuditPage::SLUG), AuditPage::latestResultsArgs());
        $this->assertArrayNotHasKey('run', AuditPage::latestResultsArgs());
    }

    private function makeRun(int $id, AuditRunStatus $status): AuditRun
    {
        return new AuditRun(
            $id,
            $status,
            '2026-01-01 00:00:00',
            $status === AuditRunStatus::Complete ? '2026-01-01 00:01:00' : null,
            '2026-01-01 00:00:00',
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            1,
            array('recipe'),
            $status === AuditRunStatus::Failed ? 'Audit batch failed.' : null
        );
    }
}
