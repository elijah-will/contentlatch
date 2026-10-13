<?php
/**
 * Cursor traversal contract for the in-memory audit store.
 *
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application\Audit;

use ContentLatch\Application\Audit\AuditFinding;
use ContentLatch\Application\Audit\AuditStoreInterface;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Tests\Support\InMemoryAuditStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InMemoryAuditStoreFindingCursorTest extends TestCase
{
    public function testBatchesStayOrderedAndIsolatedAcrossRunsAndIdGaps(): void
    {
        $store = new InMemoryAuditStore();
        $store->findings = array(
            $this->finding(24, 7),
            $this->finding(8, 8),
            $this->finding(2, 7),
            $this->finding(15, 7),
            $this->finding(1, 8),
            $this->finding(9, 7, RuleSeverity::Warning),
        );
        $original = $store->findings;

        $first = $store->findFindingsAfterId(7, 0, 2);
        $this->assertSame(array(2, 9), $this->ids($first));
        $this->assertSame(RuleSeverity::Warning, $first[1]->severity);
        $second = $store->findFindingsAfterId(7, $first[1]->id, 2);
        $this->assertSame(array(15, 24), $this->ids($second));
        $this->assertSame(array(), $store->findFindingsAfterId(7, $second[1]->id, 2));
        $this->assertSame(array(2, 9, 15, 24), $this->ids(array_merge($first, $second)));
        $this->assertSame(array(1, 8), $this->ids($store->findFindingsAfterId(8, 0, 2)));
        $this->assertSame(array(15, 24), $this->ids($store->findFindingsAfterId(7, 10, 2)));
        $this->assertSame(array(24), $this->ids($store->findFindingsAfterId(7, 15, 2)));
        $this->assertSame($original, $store->findings);
    }

    public function testEmptyStoreUnknownRunAndExhaustedCursorReturnEmptyArrays(): void
    {
        $store = new InMemoryAuditStore();
        $this->assertSame(array(), $store->findFindingsAfterId(7, 0, 2));

        $store->findings = array($this->finding(2, 7));
        $this->assertSame(array(), $store->findFindingsAfterId(8, 0, 2));
        $this->assertSame(array(), $store->findFindingsAfterId(7, 2, 2));
        $this->assertSame(array(), $store->findFindingsAfterId(7, PHP_INT_MAX, 2));
    }

    #[DataProvider('limits')]
    public function testLimitsAreAlwaysPositiveAndCapped(int $input, int $expected): void
    {
        $store = new InMemoryAuditStore();
        for ($id = AuditStoreInterface::MAX_FINDINGS_BATCH_SIZE + 1; $id > 0; $id--) {
            $store->findings[] = $this->finding($id, 7);
        }

        $batch = $store->findFindingsAfterId(7, 0, $input);

        $this->assertCount($expected, $batch);
        $this->assertSame(range(1, $expected), $this->ids($batch));
    }

    public static function limits(): array
    {
        return array(
            'minimum integer' => array(PHP_INT_MIN, 1),
            'negative'        => array(-10, 1),
            'zero'            => array(0, 1),
            'one'             => array(1, 1),
            'maximum'         => array(AuditStoreInterface::MAX_FINDINGS_BATCH_SIZE, AuditStoreInterface::MAX_FINDINGS_BATCH_SIZE),
            'above maximum'   => array(AuditStoreInterface::MAX_FINDINGS_BATCH_SIZE + 1, AuditStoreInterface::MAX_FINDINGS_BATCH_SIZE),
            'maximum integer' => array(PHP_INT_MAX, AuditStoreInterface::MAX_FINDINGS_BATCH_SIZE),
        );
    }

    public function testNegativeCursorStartsAtZero(): void
    {
        $store = new InMemoryAuditStore();
        $store->findings = array($this->finding(2, 7));

        $this->assertSame(array(2), $this->ids($store->findFindingsAfterId(7, -10, 2)));
    }

    /** @param AuditFinding[] $findings */
    private function ids(array $findings): array
    {
        return array_map(static fn (AuditFinding $finding): int => $finding->id, $findings);
    }

    private function finding(int $id, int $runId, RuleSeverity $severity = RuleSeverity::Fail): AuditFinding
    {
        return new AuditFinding(
            $id,
            $runId,
            42,
            'post',
            '12',
            'core:post_title',
            'required-title',
            'required',
            $severity,
            'Missing title',
            '2026-10-12 12:00:00'
        );
    }
}
