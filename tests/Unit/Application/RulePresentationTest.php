<?php
/**
 * @package ContentLatch
 */

declare(strict_types=1);

namespace ContentLatch\Tests\Unit\Application;

use ContentLatch\Application\FieldValuePresentation;
use ContentLatch\Application\RulePresentation;
use ContentLatch\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RulePresentationTest extends TestCase
{
    public function testTrueFalseValuesDisplayAsYesAndNo(): void
    {
        $this->assertSame('Yes', FieldValuePresentation::label('1', 'true_false'));
        $this->assertSame('Yes', FieldValuePresentation::label(1, 'true_false'));
        $this->assertSame('Yes', FieldValuePresentation::label(true, 'true_false'));
        $this->assertSame('No', FieldValuePresentation::label('0', 'true_false'));
        $this->assertSame('No', FieldValuePresentation::label(0, 'true_false'));
        $this->assertSame('No', FieldValuePresentation::label(false, 'true_false'));
    }

    public function testNonTrueFalseValuesStayLiteral(): void
    {
        $this->assertSame('1', FieldValuePresentation::label('1', 'text'));
        $this->assertSame('0', FieldValuePresentation::label('0', 'select'));
        $this->assertSame('1', FieldValuePresentation::label('1', null));
        $this->assertSame('sauce', FieldValuePresentation::label('sauce', 'true_false'));
    }

    public function testConditionSummaryUsesYesNoOnlyWhenTheFieldTypeIsKnown(): void
    {
        $rule = RuleFactory::rule(array(
            'conditions' => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field('field_signature', 'is_signature', 'Is Signature'),
                    'operator' => 'equals',
                    'operand'  => '1',
                )),
            ),
        ));

        $this->assertSame(
            'Is Signature is Yes',
            RulePresentation::conditionsSummary($rule, array('field_signature' => 'true_false'))
        );
        $this->assertSame(
            'Is Signature equals 1',
            RulePresentation::conditionsSummary($rule)
        );

        $off = RuleFactory::rule(array(
            'conditions' => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field('field_signature', 'is_signature', 'Is Signature'),
                    'operator' => 'equals',
                    'operand'  => '0',
                )),
            ),
        ));
        $this->assertSame(
            'Is Signature is No',
            RulePresentation::conditionsSummary($off, array('field_signature' => 'true_false'))
        );

        $notYes = RuleFactory::rule(array(
            'conditions' => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field('field_signature', 'is_signature', 'Is Signature'),
                    'operator' => 'not_equals',
                    'operand'  => '1',
                )),
            ),
        ));
        $this->assertSame(
            'Is Signature is not Yes',
            RulePresentation::conditionsSummary($notYes, array('field_signature' => 'true_false'))
        );
    }

    public function testNumericConditionSummaryUsesFriendlyLanguage(): void
    {
        $rule = RuleFactory::rule(array(
            'conditions' => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field('field_cook_time', 'cook_time', 'Cook Time'),
                    'operator' => 'greater_than',
                    'operand'  => '30',
                )),
            ),
        ));

        $this->assertSame(
            'Cook Time is greater than 30',
            RulePresentation::conditionsSummary($rule, array('field_cook_time' => 'number'))
        );
    }

    public function testContainsConditionSummaryUsesLiteralLanguage(): void
    {
        $contains = RuleFactory::rule(array(
            'conditions' => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field('content', 'post_content', 'Content'),
                    'operator' => 'contains',
                    'operand'  => 'healthy',
                )),
            ),
        ));
        $this->assertSame('Content contains healthy', RulePresentation::conditionsSummary($contains));

        $missing = RuleFactory::rule(array(
            'conditions' => array(
                RuleFactory::condition(array(
                    'field'    => RuleFactory::field('title', 'post_title', 'Title'),
                    'operator' => 'does_not_contain',
                    'operand'  => 'B&G',
                )),
            ),
        ));
        $this->assertSame('Title does not contain B&G', RulePresentation::conditionsSummary($missing));
    }
}
