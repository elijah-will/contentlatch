<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\ContentEvaluator;
use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\ContentStatus;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\RuleStatus;
use ContentGuard\Tests\Support\InMemoryRuleRepository;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class ContentEvaluatorTest extends TestCase
{
    public function testEvaluatesActiveRulesForPostType(): void
    {
        $rule = RuleFactory::rule(array('id' => 1, 'postType' => 'product'));
        $evaluator = $this->evaluator(array($rule));

        $evaluation = $evaluator->evaluate(
            42,
            'product',
            new ArrayValueProvider(
                array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => 'tomatoes',
                )
            )
        );

        $this->assertSame(42, $evaluation->postId);
        $this->assertSame('product', $evaluation->postType);
        $this->assertTrue($evaluation->isPassed());
        $this->assertCount(1, $evaluation->appliedResults());
    }

    public function testIgnoresInactiveRules(): void
    {
        $inactive = RuleFactory::rule(
            array(
                'id'     => 1,
                'status' => RuleStatus::Inactive,
            )
        );
        $evaluator = $this->evaluator(array($inactive));

        $evaluation = $evaluator->evaluate(
            5,
            'product',
            new ArrayValueProvider(array('field_type' => 'sauce'))
        );

        $this->assertTrue($evaluation->isNotEvaluated());
        $this->assertSame(ContentStatus::NotEvaluated, $evaluation->status);
        $this->assertCount(0, $evaluation->results);
    }

    public function testIgnoresRulesForOtherPostTypes(): void
    {
        $rule = RuleFactory::rule(array('postType' => 'product'));
        $evaluator = $this->evaluator(array($rule));

        $evaluation = $evaluator->evaluate(
            5,
            'page',
            new ArrayValueProvider(array('field_type' => 'sauce'))
        );

        $this->assertTrue($evaluation->isNotEvaluated());
    }

    public function testInactiveRuleWouldFailIfEngineReceivedItDirectly(): void
    {
        $inactive = RuleFactory::rule(
            array(
                'status' => RuleStatus::Inactive,
            )
        );

        $engineEvaluation = RuleEngine::v1()->evaluate(
            array($inactive),
            new ArrayValueProvider(array('field_type' => 'sauce')),
            9
        );
        $this->assertTrue($engineEvaluation->isFailed());

        $evaluatorEvaluation = $this->evaluator(array($inactive))->evaluate(
            9,
            'product',
            new ArrayValueProvider(array('field_type' => 'sauce'))
        );
        $this->assertTrue($evaluatorEvaluation->isNotEvaluated());
    }

    public function testSkippedRulesAreNotPassed(): void
    {
        $rule = RuleFactory::rule();
        $evaluation = $this->evaluator(array($rule))->evaluate(
            3,
            'product',
            new ArrayValueProvider(array('field_type' => 'dip'))
        );

        $this->assertTrue($evaluation->isNotEvaluated());
        $this->assertCount(1, $evaluation->skippedResults());
        $this->assertFalse($evaluation->isPassed());
    }

    public function testConditionOnlyNonMatchIsPassed(): void
    {
        $rule = RuleFactory::rule(array(
            'conditions'  => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field('content', 'post_content', 'Content'),
                    'operator' => 'contains',
                    'operand'  => 'healthy',
                )),
            ),
            'validations' => array(),
        ));

        $evaluation = $this->evaluator(array($rule))->evaluate(
            3,
            'product',
            new ArrayValueProvider(array('content' => 'A tasty salad'))
        );

        $this->assertTrue($evaluation->isPassed());
        $this->assertFalse($evaluation->isNotEvaluated());
        $this->assertSame('conditions_not_met', $evaluation->results[0]->code);
    }

    public function testFailedActiveRule(): void
    {
        $evaluation = $this->evaluator(array(RuleFactory::rule()))->evaluate(
            8,
            'product',
            new ArrayValueProvider(array('field_type' => 'sauce'))
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertSame(8, $evaluation->results[0]->postId);
    }

    /**
     * @param array<int, \ContentGuard\Domain\Rule> $rules
     */
    private function evaluator(array $rules): ContentEvaluator
    {
        return new ContentEvaluator(
            new InMemoryRuleRepository($rules),
            RuleEngine::v1()
        );
    }
}
