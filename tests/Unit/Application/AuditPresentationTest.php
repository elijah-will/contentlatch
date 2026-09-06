<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Application;

use ContentGuard\Application\AuditPresentation;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class AuditPresentationTest extends TestCase
{
    public function testRuleNameFallsBackWhenTheRuleIsMissing(): void
    {
        $rule = RuleFactory::rule(array('name' => 'Sauces require ingredients'));

        $this->assertSame('Sauces require ingredients', AuditPresentation::ruleName($rule, 10));
        $this->assertSame('Deleted rule', AuditPresentation::ruleName(null, 10));
    }

    public function testFieldLabelPrefersSnapshotThenKey(): void
    {
        $rule = RuleFactory::rule(array(
            'validations' => array(
                RuleFactory::validation(array(
                    'field' => RuleFactory::field('field_description', 'recipe_description', 'Recipe Description'),
                )),
            ),
        ));

        $this->assertSame('Recipe Description', AuditPresentation::fieldLabel($rule, 'field_description'));
        $this->assertSame('field_missing', AuditPresentation::fieldLabel($rule, 'field_missing'));
        $this->assertSame('field_description', AuditPresentation::fieldLabel(null, 'field_description'));

        $conditionOnly = RuleFactory::rule(array(
            'conditions' => array(
                RuleFactory::condition(array(
                    'field' => RuleFactory::field('field_signature', 'is_signature', 'Is Signature'),
                )),
            ),
            'validations' => array(),
        ));
        $this->assertSame('Is Signature', AuditPresentation::fieldLabel($conditionOnly, 'field_signature'));
    }

    public function testPostTitleAndSeverityStayReadable(): void
    {
        $this->assertSame('Tomato Sauce', AuditPresentation::postTitle('Tomato Sauce'));
        $this->assertSame('Content no longer available', AuditPresentation::postTitle(''));
        $this->assertSame('Blocking', AuditPresentation::severityLabel(RuleSeverity::Fail));
        $this->assertSame('Warning', AuditPresentation::severityLabel(RuleSeverity::Warning));
    }
}
