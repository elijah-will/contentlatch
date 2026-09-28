<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application\Audit;

use ContentLatch\Application\Audit\AuditFinding;
use ContentLatch\Application\Audit\AuditRepeaterCoordinates;
use ContentLatch\Domain\EvaluationResult;
use ContentLatch\Domain\EvaluationStatus;
use ContentLatch\Domain\RuleSeverity;
use ContentLatch\Tests\Support\AcfNestedRepeaterFixtures;
use PHPUnit\Framework\TestCase;

final class AuditRepeaterCoordinatesTest extends TestCase
{
    public function testInstanceChainKeepsOuterThenInnerOrdering(): void
    {
        $chain = AuditRepeaterCoordinates::instanceChain(array(
            'repeater_rows' => array(
                array(
                    'repeater'    => AcfNestedRepeaterFixtures::DIRECTIONS,
                    'key'         => 'row-1',
                    'index'       => 1,
                    'display_row' => 2,
                ),
                array(
                    'repeater'    => AcfNestedRepeaterFixtures::STEPS,
                    'key'         => 'row-2',
                    'index'       => 2,
                    'display_row' => 3,
                ),
            ),
        ));

        $this->assertSame(AcfNestedRepeaterFixtures::DIRECTIONS, $chain[0]['repeater']);
        $this->assertSame(2, $chain[0]['display_row']);
        $this->assertSame(AcfNestedRepeaterFixtures::STEPS, $chain[1]['repeater']);
        $this->assertSame(3, $chain[1]['display_row']);
        $this->assertSame('2/3', AuditRepeaterCoordinates::pairToken($chain));
    }

    public function testOneLevelDisplayRowIsNotANestedChain(): void
    {
        $this->assertSame(array(), AuditRepeaterCoordinates::instanceChain(array(
            'display_row' => 3,
            'row_key'     => 'row-2',
        )));
        $this->assertSame(array(), AuditRepeaterCoordinates::cellsFromResults(array(
            $this->failedResult(array('display_row' => 3)),
        )));
    }

    public function testCollapsedCellsKeepDistinctOuterInnerPairs(): void
    {
        $cells = AuditRepeaterCoordinates::cellsFromResults(array(
            $this->failedResult($this->chain(1, 2)),
            $this->failedResult($this->chain(2, 1)),
            $this->failedResult($this->chain(1, 2)),
        ));

        $this->assertCount(2, $cells);
        $this->assertSame('1/2', AuditRepeaterCoordinates::pairToken($cells[0]));
        $this->assertSame('2/1', AuditRepeaterCoordinates::pairToken($cells[1]));
    }

    public function testSnapshotRoundTripPreservesPairedCoordinates(): void
    {
        $cells = array(
            AuditRepeaterCoordinates::instanceChain($this->chain(1, 1)),
            AuditRepeaterCoordinates::instanceChain($this->chain(1, 2)),
            AuditRepeaterCoordinates::instanceChain($this->chain(2, 1)),
        );
        $message = AuditRepeaterCoordinates::formatSnapshot('Name is required.', $cells);

        $this->assertSame('Name is required in 3 rows (rows 1/1, 1/2, 2/1).', $message);
        $this->assertSame(
            'Name is required in row 2/1.',
            AuditRepeaterCoordinates::formatSnapshot('Name is required.', array(
                AuditRepeaterCoordinates::instanceChain($this->chain(2, 1)),
            ))
        );
        $this->assertSame(
            array(array(2, 1)),
            AuditRepeaterCoordinates::pairsFromFinding($this->finding('Name is required in row 2/1.'))
        );
        $this->assertSame(
            array(array(1, 1), array(1, 2), array(2, 1)),
            AuditRepeaterCoordinates::pairsFromFinding($this->finding($message))
        );
        $this->assertSame(
            'Can\'t contain the word "chicken" in row 1/5.',
            AuditRepeaterCoordinates::formatSnapshot(
                'Can\'t contain the word "chicken"',
                array(AuditRepeaterCoordinates::instanceChain($this->chain(1, 5)))
            )
        );
        $this->assertSame(
            array(),
            AuditRepeaterCoordinates::cellsFromSnapshot('Ingredient is required in 3 rows (rows 1, 3, 5).')
        );
        $this->assertSame(
            array(),
            AuditRepeaterCoordinates::cellsFromSnapshot('Title is required in 2 Hero rows (rows 1, 3).')
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function failedResult(array $context): EvaluationResult
    {
        return new EvaluationResult(
            EvaluationStatus::Failed,
            1,
            10,
            AcfNestedRepeaterFixtures::STEP_NAME,
            'This field is required.',
            RuleSeverity::Fail,
            'required',
            $context
        );
    }

    /**
     * @return array{repeater_rows: list<array<string, mixed>>}
     */
    private function chain(int $outer, int $inner): array
    {
        return array(
            'repeater_rows' => array(
                array(
                    'repeater'    => AcfNestedRepeaterFixtures::DIRECTIONS,
                    'key'         => 'row-' . ($outer - 1),
                    'index'       => $outer - 1,
                    'display_row' => $outer,
                ),
                array(
                    'repeater'    => AcfNestedRepeaterFixtures::STEPS,
                    'key'         => 'row-' . ($inner - 1),
                    'index'       => $inner - 1,
                    'display_row' => $inner,
                ),
            ),
        );
    }

    private function finding(string $message): AuditFinding
    {
        return new AuditFinding(
            1,
            1,
            10,
            'recipe',
            1,
            AcfNestedRepeaterFixtures::STEP_NAME,
            'v1',
            'required',
            RuleSeverity::Fail,
            $message,
            '2026-01-01 00:00:00'
        );
    }
}
