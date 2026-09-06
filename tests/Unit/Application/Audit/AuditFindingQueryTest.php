<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application\Audit;

use ContentGuard\Application\Audit\AuditFinding;
use ContentGuard\Application\Audit\AuditFindingQuery;
use ContentGuard\Application\Audit\AuditRun;
use ContentGuard\Application\Audit\AuditRunStatus;
use ContentGuard\Application\Audit\ContentAuditService;
use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Infrastructure\ACF\AcfFieldCatalog;
use ContentGuard\Infrastructure\InMemory\InMemoryRuleRepository;
use ContentGuard\Tests\Support\InMemoryAuditLock;
use ContentGuard\Tests\Support\InMemoryAuditPostScanner;
use ContentGuard\Tests\Support\InMemoryAuditStore;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AuditFindingQueryTest extends TestCase
{
    public function testQueryNormalizesInvalidFiltersAndHasFilters(): void
    {
        $empty = new AuditFindingQuery(3, 'all', '0', '', 0, -4);

        $this->assertNull($empty->severity);
        $this->assertNull($empty->ruleId);
        $this->assertNull($empty->postType);
        $this->assertSame(1, $empty->limit);
        $this->assertSame(0, $empty->offset);
        $this->assertFalse($empty->hasFilters());

        $filtered = new AuditFindingQuery(3, 'fail', 12, 'recipe', 25, 50);

        $this->assertSame('fail', $filtered->severity);
        $this->assertSame(12, $filtered->ruleId);
        $this->assertSame('recipe', $filtered->postType);
        $this->assertTrue($filtered->hasFilters());
    }

    public function testCountsFiltersAndPaginationStayOnTheSelectedRun(): void
    {
        $store   = $this->seededStore();
        $service = $this->service($store);

        $all = new AuditFindingQuery(1);
        $this->assertSame(5, $service->countFindings($all));
        $this->assertSame(3, $service->countDistinctPosts($all));
        $this->assertSame(
            array('fail' => 3, 'warning' => 2),
            $service->countFindingsBySeverity(1)
        );

        $fails = new AuditFindingQuery(1, 'fail');
        $this->assertSame(3, $service->countFindings($fails));
        $this->assertSame(2, $service->countDistinctPosts($fails));

        $rule = new AuditFindingQuery(1, null, 10);
        $this->assertSame(3, $service->countFindings($rule));
        $this->assertSame(2, $service->countDistinctPosts($rule));

        $type = new AuditFindingQuery(1, null, null, 'page');
        $this->assertSame(1, $service->countFindings($type));
        $this->assertSame(1, $service->countDistinctPosts($type));

        $combined = new AuditFindingQuery(1, 'fail', 10, 'recipe');
        $this->assertSame(2, $service->countFindings($combined));
        $this->assertCount(2, $service->queryFindings($combined));

        $page = new AuditFindingQuery(1, null, null, null, 2, 2);
        $ids  = array_map(static fn (AuditFinding $finding): int => $finding->id, $service->queryFindings($page));
        $this->assertSame(array(3, 4), $ids);
        $this->assertSame(5, $service->countFindings($page));
    }

    public function testFindingAndAffectedPostCountsAreNotDerivedFromRunRollups(): void
    {
        $store   = $this->seededStore();
        $service = $this->service($store);
        $run     = $service->getLatestCompleteRun();

        $this->assertNotNull($run);
        $this->assertSame(2, $run->postsFailed);
        $this->assertSame(1, $run->postsWarned);

        $all = new AuditFindingQuery($run->id);
        $this->assertSame(5, $service->countFindings($all));
        $this->assertSame(3, $service->countDistinctPosts($all));
        $this->assertNotSame($run->postsFailed + $run->postsWarned, $service->countFindings($all));
    }

    public function testRuleImpactsUseFindingAndPostCountsSeparately(): void
    {
        $store   = $this->seededStore();
        $service = $this->service($store);
        $byRule  = array();

        foreach ($service->countFindingsByRule(1) as $impact) {
            $byRule[(string) $impact->ruleId] = $impact;
        }

        $this->assertSame(3, $byRule['10']->findingCount);
        $this->assertSame(2, $byRule['10']->postCount);
        $this->assertSame(2, $byRule['20']->findingCount);
        $this->assertSame(2, $byRule['20']->postCount);
    }

    public function testHistoryListsRecentRunsWithoutChangingLatestComplete(): void
    {
        $store = $this->seededStore();
        $store->saveRun(
            new AuditRun(
                2,
                AuditRunStatus::Failed,
                '2026-09-04 12:00:00',
                '2026-09-04 12:01:00',
                '2026-09-04 12:01:00',
                10,
                0,
                0,
                0,
                0,
                10,
                0,
                1,
                array('recipe'),
                'Audit batch failed.'
            )
        );
        $store->saveRun(
            new AuditRun(
                3,
                AuditRunStatus::Cancelled,
                '2026-09-05 12:00:00',
                '2026-09-05 12:01:00',
                '2026-09-05 12:01:00',
                4,
                0,
                0,
                0,
                0,
                20,
                0,
                1,
                array('recipe'),
                null
            )
        );

        $service = $this->service($store);
        $recent  = $service->listRecentRuns(20);

        $this->assertSame(array(3, 2, 1), array_map(static fn (AuditRun $run): int => $run->id, $recent));
        $this->assertSame(3, $service->countRuns());
        $this->assertSame(array(2, 1), array_map(static fn (AuditRun $run): int => $run->id, $service->listRecentRuns(2, 1)));
        $this->assertSame(1, $service->getLatestCompleteRun()?->id);
        $this->assertSame(3, $service->getLatestRun()?->id);
    }

    public function testInvalidFiltersAreIgnoredAndUnknownRunIsRejected(): void
    {
        $store   = $this->seededStore();
        $service = $this->service($store);
        $query   = new AuditFindingQuery(1, 'not-a-severity', '0', '');

        $this->assertFalse($query->hasFilters());
        $this->assertSame(5, $service->countFindings($query));

        $this->expectException(\ContentGuard\Application\Exception\AuditException::class);
        $this->expectExceptionMessage('Audit run not found.');
        $service->queryFindings(new AuditFindingQuery(99));
    }

    private function seededStore(): InMemoryAuditStore
    {
        $store = new InMemoryAuditStore();
        $store->runs[1] = new AuditRun(
            1,
            AuditRunStatus::Complete,
            '2026-09-03 12:00:00',
            '2026-09-03 12:05:00',
            '2026-09-03 12:05:00',
            10,
            7,
            1,
            2,
            0,
            10,
            40,
            1,
            array('recipe', 'page'),
            null
        );
        $store->nextRunId = 2;

        $store->findings = array(
            $this->finding(1, 1, 11, 'recipe', 10, RuleSeverity::Fail),
            $this->finding(2, 1, 11, 'recipe', 10, RuleSeverity::Fail, 'v2'),
            $this->finding(3, 1, 12, 'recipe', 10, RuleSeverity::Warning),
            $this->finding(4, 1, 12, 'recipe', 20, RuleSeverity::Warning),
            $this->finding(5, 1, 13, 'page', 20, RuleSeverity::Fail),
        );
        $store->nextFindingId = 6;

        return $store;
    }

    private function finding(
        int $id,
        int $runId,
        int $postId,
        string $postType,
        int $ruleId,
        RuleSeverity $severity,
        string $validationId = 'v1',
    ): AuditFinding {
        return new AuditFinding(
            $id,
            $runId,
            $postId,
            $postType,
            $ruleId,
            'field_description',
            $validationId,
            'required',
            $severity,
            'Missing',
            '2026-09-03 12:00:00'
        );
    }

    private function service(InMemoryAuditStore $store): ContentAuditService
    {
        return new ContentAuditService(
            $store,
            new InMemoryAuditPostScanner(array()),
            new InMemoryAuditLock(),
            new InMemoryRuleRepository(array(RuleFactory::rule())),
            new ContentEvaluator(new InMemoryRuleRepository(array(RuleFactory::rule())), RuleEngine::v1()),
            new AcfFieldCatalog(static fn (): array => array()),
            static fn (): ArrayValueProvider => new ArrayValueProvider(array()),
            static function (array $ids): void {
                unset($ids);
            },
            static fn (): int => 1_000_000
        );
    }
}
