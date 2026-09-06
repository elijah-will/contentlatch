<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\FieldValuePresentation;
use ContentGuard\Application\RulePresentation;
use ContentGuard\Tests\Support\RuleFactory;
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
}
