<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Domain;

use ContentLatch\Domain\ContentEvaluation;
use ContentLatch\Domain\ContentStatus;
use ContentLatch\Domain\EvaluationResult;
use ContentLatch\Domain\EvaluationStatus;
use ContentLatch\Domain\RuleSeverity;
use PHPUnit\Framework\TestCase;

final class ContentEvaluationTest extends TestCase
{
    public function testEmptyResultsAreNotEvaluated(): void
    {
        $evaluation = ContentEvaluation::fromResults(1, array());
        $this->assertTrue($evaluation->isNotEvaluated());
    }

    public function testAllSkippedIsNotEvaluated(): void
    {
        $evaluation = ContentEvaluation::fromResults(
            1,
            array(
                $this->makeResult(EvaluationStatus::Skipped),
                $this->makeResult(EvaluationStatus::Skipped, 2),
            )
        );

        $this->assertSame(ContentStatus::NotEvaluated, $evaluation->status);
        $this->assertCount(2, $evaluation->skippedResults());
        $this->assertCount(0, $evaluation->appliedResults());
    }

    public function testPassedWinsOverSkipped(): void
    {
        $evaluation = ContentEvaluation::fromResults(
            1,
            array(
                $this->makeResult(EvaluationStatus::Skipped),
                $this->makeResult(EvaluationStatus::Passed, 2),
            )
        );

        $this->assertTrue($evaluation->isPassed());
    }

    public function testWarningWinsOverPassed(): void
    {
        $evaluation = ContentEvaluation::fromResults(
            1,
            array(
                $this->makeResult(EvaluationStatus::Passed),
                $this->makeResult(EvaluationStatus::Warning, 2, RuleSeverity::Warning),
            )
        );

        $this->assertTrue($evaluation->isWarning());
    }

    public function testFailedWinsOverWarningAndPassed(): void
    {
        $evaluation = ContentEvaluation::fromResults(
            1,
            array(
                $this->makeResult(EvaluationStatus::Passed),
                $this->makeResult(EvaluationStatus::Warning, 2, RuleSeverity::Warning),
                $this->makeResult(EvaluationStatus::Failed, 3),
            )
        );

        $this->assertTrue($evaluation->isFailed());
    }

    private function makeResult(
        EvaluationStatus $status,
        int|string $ruleId = 1,
        RuleSeverity $severity = RuleSeverity::Fail,
    ): EvaluationResult {
        return new EvaluationResult(
            $status,
            $ruleId,
            1,
            'field_x',
            'test',
            $severity,
            'test',
        );
    }
}
