<?php
/**
 * @package ContentGuard
 */

declare(strict_types=1);

namespace ContentGuard\Tests\Unit\Domain;

use ContentGuard\Domain\ArrayValueProvider;
use ContentGuard\Domain\ContentStatus;
use ContentGuard\Domain\EvaluationStatus;
use ContentGuard\Domain\Exception\UnknownOperatorException;
use ContentGuard\Domain\Exception\UnknownValidatorException;
use ContentGuard\Domain\RuleEngine;
use ContentGuard\Domain\RuleSeverity;
use ContentGuard\Tests\Support\RuleFactory;
use PHPUnit\Framework\TestCase;

final class RuleEngineTest extends TestCase
{
    private RuleEngine $engine;

    protected function setUp(): void
    {
        $this->engine = RuleEngine::v1();
    }

    public function testSuccessfulConditionalRequiredRule(): void
    {
        $rule = RuleFactory::rule();
        $provider = new ArrayValueProvider(
            array(
                'field_type'        => 'sauce',
                'field_ingredients' => 'tomatoes',
            )
        );

        $evaluation = $this->engine->evaluate(array($rule), $provider, 42);

        $this->assertTrue($evaluation->isPassed());
        $this->assertSame(42, $evaluation->postId);
        $this->assertCount(1, $evaluation->results);
        $this->assertTrue($evaluation->results[0]->isPassed());
        $this->assertSame('field_ingredients', $evaluation->results[0]->fieldId);
        $this->assertSame('required', $evaluation->results[0]->code);
    }

    public function testValidationFailureWhenRequiredFieldMissing(): void
    {
        $rule = RuleFactory::rule();
        $provider = new ArrayValueProvider(array('field_type' => 'sauce'));

        $evaluation = $this->engine->evaluate(array($rule), $provider, 7);

        $this->assertTrue($evaluation->isFailed());
        $this->assertTrue($evaluation->results[0]->isFailed());
        $this->assertSame('required', $evaluation->results[0]->code);
        $this->assertSame(7, $evaluation->results[0]->postId);
        $this->assertSame('This field is required.', $evaluation->results[0]->message);
    }

    public function testEmptyNullAndFalseFailRequired(): void
    {
        $rule = RuleFactory::rule();

        foreach (array(null, '', false, array()) as $empty) {
            $provider = new ArrayValueProvider(
                array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => $empty,
                )
            );
            $evaluation = $this->engine->evaluate(array($rule), $provider);
            $this->assertTrue($evaluation->isFailed(), 'Empty value should fail required.');
        }
    }

    public function testSkippedWhenConditionsDoNotMatch(): void
    {
        $rule = RuleFactory::rule();
        $provider = new ArrayValueProvider(
            array(
                'field_type'        => 'dip',
                'field_ingredients' => '',
            )
        );

        $evaluation = $this->engine->evaluate(array($rule), $provider, 3);

        $this->assertTrue($evaluation->isNotEvaluated());
        $this->assertCount(1, $evaluation->results);
        $this->assertTrue($evaluation->results[0]->isSkipped());
        $this->assertSame('conditions_not_met', $evaluation->results[0]->code);
        $this->assertNull($evaluation->results[0]->fieldId);
    }

    public function testNotEqualsCondition(): void
    {
        $rule = RuleFactory::rule(
            array(
                'conditions' => array(
                    RuleFactory::condition(
                        array(
                            'operator' => 'not_equals',
                            'operand'  => 'internal',
                        )
                    ),
                ),
            )
        );

        $applies = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(
                array(
                    'field_type'        => 'external',
                    'field_ingredients' => 'ok',
                )
            )
        );
        $this->assertTrue($applies->isPassed());

        $skipped = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(
                array(
                    'field_type'        => 'internal',
                    'field_ingredients' => '',
                )
            )
        );
        $this->assertTrue($skipped->results[0]->isSkipped());
    }

    public function testEmptyAndNotEmptyConditions(): void
    {
        $whenEmpty = RuleFactory::rule(
            array(
                'conditions'  => array(
                    RuleFactory::condition(
                        array(
                            'operator' => 'is_empty',
                            'operand'  => null,
                            'field'    => RuleFactory::field('field_notes', 'notes', 'Notes'),
                        )
                    ),
                ),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => RuleFactory::field('field_alt', 'alt', 'Alt'),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );

        $evaluation = $this->engine->evaluate(
            array($whenEmpty),
            new ArrayValueProvider(
                array(
                    'field_notes' => '',
                    'field_alt'   => 'fallback',
                )
            )
        );
        $this->assertTrue($evaluation->isPassed());

        $whenNotEmpty = RuleFactory::rule(
            array(
                'id'          => 2,
                'conditions'  => array(
                    RuleFactory::condition(
                        array(
                            'operator' => 'is_not_empty',
                            'operand'  => null,
                            'field'    => RuleFactory::field('field_description', 'description', 'Description'),
                        )
                    ),
                ),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field'  => RuleFactory::field('field_description', 'description', 'Description'),
                            'type'   => 'min_length',
                            'params' => array('min' => 100),
                        )
                    ),
                ),
            )
        );

        $tooShort = $this->engine->evaluate(
            array($whenNotEmpty),
            new ArrayValueProvider(array('field_description' => 'short'))
        );
        $this->assertTrue($tooShort->isFailed());
        $this->assertSame('min_length', $tooShort->results[0]->code);

        $longEnough = $this->engine->evaluate(
            array($whenNotEmpty),
            new ArrayValueProvider(array('field_description' => str_repeat('x', 100)))
        );
        $this->assertTrue($longEnough->isPassed());
    }

    public function testMultipleConditionsUseAndLogic(): void
    {
        $rule = RuleFactory::rule(
            array(
                'conditions' => array(
                    RuleFactory::condition(
                        array(
                            'id'       => 'c1',
                            'operator' => 'equals',
                            'operand'  => 'external',
                            'field'    => RuleFactory::field('field_cta_type', 'cta_type', 'CTA Type'),
                        )
                    ),
                    RuleFactory::condition(
                        array(
                            'id'       => 'c2',
                            'operator' => 'is_not_empty',
                            'operand'  => null,
                            'field'    => RuleFactory::field('field_title', 'title', 'Title'),
                        )
                    ),
                ),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => RuleFactory::field('field_cta_url', 'cta_url', 'CTA URL'),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );

        $bothMatch = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(
                array(
                    'field_cta_type' => 'external',
                    'field_title'    => 'Learn more',
                    'field_cta_url'  => 'https://example.com',
                )
            )
        );
        $this->assertTrue($bothMatch->isPassed());

        $firstFails = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(
                array(
                    'field_cta_type' => 'internal',
                    'field_title'    => 'Learn more',
                    'field_cta_url'  => '',
                )
            )
        );
        $this->assertTrue($firstFails->results[0]->isSkipped());

        $secondFails = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(
                array(
                    'field_cta_type' => 'external',
                    'field_title'    => '',
                    'field_cta_url'  => '',
                )
            )
        );
        $this->assertTrue($secondFails->results[0]->isSkipped());
    }

    public function testRuleWithNoConditionsAlwaysApplies(): void
    {
        $rule = RuleFactory::rule(
            array(
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field'  => RuleFactory::field('field_description', 'description', 'Description'),
                            'type'   => 'min_length',
                            'params' => array('min' => 10),
                        )
                    ),
                ),
            )
        );

        $pass = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array('field_description' => 'long enough text'))
        );
        $this->assertTrue($pass->isPassed());

        $fail = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array('field_description' => 'short'))
        );
        $this->assertTrue($fail->isFailed());
    }

    public function testMultipleValidationsOnOneRule(): void
    {
        $description = RuleFactory::field('field_description', 'description', 'Description');
        $rule = RuleFactory::rule(
            array(
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'id'     => 'v1',
                            'field'  => $description,
                            'type'   => 'required',
                        )
                    ),
                    RuleFactory::validation(
                        array(
                            'id'     => 'v2',
                            'field'  => $description,
                            'type'   => 'min_length',
                            'params' => array('min' => 10),
                        )
                    ),
                    RuleFactory::validation(
                        array(
                            'id'     => 'v3',
                            'field'  => $description,
                            'type'   => 'max_length',
                            'params' => array('max' => 20),
                        )
                    ),
                ),
            )
        );

        $allPass = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array('field_description' => 'abcdefghij'))
        );
        $this->assertTrue($allPass->isPassed());
        $this->assertCount(3, $allPass->results);

        $minFails = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array('field_description' => 'short'))
        );
        $this->assertTrue($minFails->isFailed());
        $this->assertTrue($minFails->results[0]->isPassed());
        $this->assertTrue($minFails->results[1]->isFailed());
        $this->assertTrue($minFails->results[2]->isPassed());
    }

    public function testAllowedValuesValidation(): void
    {
        $rule = RuleFactory::rule(
            array(
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field'  => RuleFactory::field('field_type', 'product_type', 'Product Type'),
                            'type'   => 'allowed_values',
                            'params' => array('values' => array('sauce', 'dip')),
                        )
                    ),
                ),
            )
        );

        $this->assertTrue(
            $this->engine->evaluate(
                array($rule),
                new ArrayValueProvider(array('field_type' => 'sauce'))
            )->isPassed()
        );
        $this->assertTrue(
            $this->engine->evaluate(
                array($rule),
                new ArrayValueProvider(array('field_type' => 'soup'))
            )->isFailed()
        );
    }

    public function testMaxLengthFailure(): void
    {
        $rule = RuleFactory::rule(
            array(
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field'  => RuleFactory::field('field_code', 'code', 'Code'),
                            'type'   => 'max_length',
                            'params' => array('max' => 3),
                        )
                    ),
                ),
            )
        );

        $evaluation = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array('field_code' => 'abcd'))
        );
        $this->assertTrue($evaluation->isFailed());
        $this->assertSame('max_length', $evaluation->results[0]->code);
    }

    public function testWarningSeverityDoesNotFailContent(): void
    {
        $rule = RuleFactory::rule(
            array(
                'severity'    => RuleSeverity::Warning,
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field'   => RuleFactory::field('field_seo', 'seo', 'SEO'),
                            'type'    => 'min_length',
                            'params'  => array('min' => 50),
                            'message' => 'SEO text should be longer.',
                        )
                    ),
                ),
            )
        );

        $evaluation = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array('field_seo' => 'short')),
            11
        );

        $this->assertTrue($evaluation->isWarning());
        $this->assertFalse($evaluation->isFailed());
        $this->assertTrue($evaluation->results[0]->isWarning());
        $this->assertSame(RuleSeverity::Warning, $evaluation->results[0]->severity);
        $this->assertSame('SEO text should be longer.', $evaluation->results[0]->message);
    }

    public function testCustomMessageUsedOnFailureOnly(): void
    {
        $rule = RuleFactory::rule(
            array(
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'message' => 'Ingredients are required for sauces.',
                        )
                    ),
                ),
            )
        );

        $failed = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array('field_type' => 'sauce'))
        );
        $this->assertSame('Ingredients are required for sauces.', $failed->results[0]->message);

        $passed = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(
                array(
                    'field_type'        => 'sauce',
                    'field_ingredients' => 'salt',
                )
            )
        );
        $this->assertSame('', $passed->results[0]->message);
    }

    public function testMissingConditionFieldIsTreatedAsEmpty(): void
    {
        $rule = RuleFactory::rule(
            array(
                'conditions' => array(
                    RuleFactory::condition(array('operator' => 'is_empty', 'operand' => null)),
                ),
            )
        );

        $evaluation = $this->engine->evaluate(
            array($rule),
            new ArrayValueProvider(array('field_ingredients' => 'present'))
        );
        $this->assertTrue($evaluation->isPassed());
    }

    public function testFailTakesPrecedenceOverWarningInRollup(): void
    {
        $warning = RuleFactory::rule(
            array(
                'id'          => 1,
                'severity'    => RuleSeverity::Warning,
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field'  => RuleFactory::field('field_a', 'a', 'A'),
                            'type'   => 'required',
                        )
                    ),
                ),
            )
        );
        $fail = RuleFactory::rule(
            array(
                'id'          => 2,
                'severity'    => RuleSeverity::Fail,
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'id'     => 'v2',
                            'field'  => RuleFactory::field('field_b', 'b', 'B'),
                            'type'   => 'required',
                        )
                    ),
                ),
            )
        );

        $evaluation = $this->engine->evaluate(
            array($warning, $fail),
            new ArrayValueProvider(array())
        );

        $this->assertTrue($evaluation->isFailed());
        $this->assertTrue($evaluation->results[0]->isWarning());
        $this->assertTrue($evaluation->results[1]->isFailed());
    }

    public function testSkippedPlusPassedIsPassed(): void
    {
        $conditional = RuleFactory::rule(array('id' => 1));
        $always = RuleFactory::rule(
            array(
                'id'          => 2,
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(
                        array(
                            'field' => RuleFactory::field('field_title', 'title', 'Title'),
                            'type'  => 'required',
                        )
                    ),
                ),
            )
        );

        $evaluation = $this->engine->evaluate(
            array($conditional, $always),
            new ArrayValueProvider(
                array(
                    'field_type'  => 'dip',
                    'field_title' => 'Hello',
                )
            )
        );

        $this->assertTrue($evaluation->isPassed());
        $this->assertCount(1, $evaluation->skippedResults());
        $this->assertCount(1, $evaluation->appliedResults());
    }

    public function testNoRulesIsNotEvaluated(): void
    {
        $evaluation = $this->engine->evaluate(array(), new ArrayValueProvider(array()), 9);
        $this->assertTrue($evaluation->isNotEvaluated());
        $this->assertSame(ContentStatus::NotEvaluated, $evaluation->status);
        $this->assertSame(9, $evaluation->postId);
    }

    public function testUnknownOperatorThrows(): void
    {
        $this->expectException(UnknownOperatorException::class);

        $rule = RuleFactory::rule(
            array(
                'conditions' => array(
                    RuleFactory::condition(array('operator' => 'contains')),
                ),
            )
        );

        $this->engine->evaluate(array($rule), new ArrayValueProvider(array('field_type' => 'sauce')));
    }

    public function testUnknownValidatorThrows(): void
    {
        $this->expectException(UnknownValidatorException::class);

        $rule = RuleFactory::rule(
            array(
                'conditions'  => array(),
                'validations' => array(
                    RuleFactory::validation(array('type' => 'regex')),
                ),
            )
        );

        $this->engine->evaluate(array($rule), new ArrayValueProvider(array()));
    }

    public function testFromArrayRoundTrip(): void
    {
        $rule = RuleFactory::rule(
            array(
                'updatedAt' => '2026-09-03T12:00:00+00:00',
            )
        );

        $restored = \ContentGuard\Domain\Rule::fromArray($rule->toArray());
        $this->assertSame($rule->name, $restored->name);
        $this->assertSame($rule->postType, $restored->postType);
        $this->assertSame($rule->conditions[0]->operator, $restored->conditions[0]->operator);
        $this->assertSame($rule->validations[0]->field->key, $restored->validations[0]->field->key);
    }
}
